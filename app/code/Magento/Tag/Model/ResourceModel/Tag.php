<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 *
 * @license Open Software License (OSL 3.0) / Academic Free License (AFL 3.0), see LICENSE.txt and LICENSE_AFL.txt
 */
declare(strict_types=1);

namespace Magento\Tag\Model\ResourceModel;

use Magento\Framework\DataObject;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\Stdlib\StringUtils;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tag\Model\Tag as TagModel;

/**
 * Tag resource model (port of Mage_Tag_Model_Resource_Tag)
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Tag extends AbstractDb
{
    /**
     * @param Context $context
     * @param StringUtils $string
     * @param StoreManagerInterface $storeManager
     * @param string|null $connectionName
     */
    public function __construct(
        Context $context,
        private readonly StringUtils $string,
        private readonly StoreManagerInterface $storeManager,
        $connectionName = null
    ) {
        parent::__construct($context, $connectionName);
    }

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init('tag', 'tag_id');
    }

    /**
     * Initialize unique fields
     *
     * @return $this
     */
    protected function _initUniqueFields()
    {
        $this->_uniqueFields = [[
            'field' => 'name',
            'title' => __('Tag'),
        ]];
        return $this;
    }

    /**
     * Loading tag by name
     *
     * @param TagModel|DataObject $model
     * @param string $name
     * @return null|false
     */
    public function loadByName($model, $name)
    {
        if (!$name) {
            return false;
        }

        $connection = $this->getConnection();
        $select = $connection->select();
        if ($this->string->strlen($name) > 255) {
            $name = $this->string->substr($name, 0, 255);
        }

        $select->from($this->getMainTable())
            ->where('name = :name');
        $data = $connection->fetchRow($select, ['name' => $name]);

        $model->setData(is_array($data) ? $data : []);

        return null;
    }

    /**
     * Before saving actions
     *
     * @param AbstractModel|TagModel $object
     * @return $this
     */
    protected function _beforeSave(AbstractModel $object)
    {
        if (!$object->getId() && $object->getStatus() == $object->getApprovedStatus()) {
            $searchTag = new DataObject();
            $this->loadByName($searchTag, $object->getName());
            if ($searchTag->getData($this->getIdFieldName())
                && $searchTag->getStatus() == $object->getPendingStatus()
            ) {
                $object->setId($searchTag->getData($this->getIdFieldName()));
            }
        }

        if ($this->string->strlen((string)$object->getName()) > 255) {
            $object->setName($this->string->substr($object->getName(), 0, 255));
        }

        return parent::_beforeSave($object);
    }

    /**
     * Saving tag's base popularity
     *
     * @param AbstractModel|TagModel $object
     * @return $this
     */
    protected function _afterSave(AbstractModel $object)
    {
        if (!$object->getStore() || !$this->isAdminStore()) {
            return parent::_afterSave($object);
        }

        $tagId = $object->isObjectNew() ? $object->getTagId() : $object->getId();

        $this->getConnection()->insertOnDuplicate($this->getTable('tag_properties'), [
            'tag_id' => $tagId,
            'store_id' => $object->getStore(),
            'base_popularity' => $object->getBasePopularity() ?: 0,
        ]);

        return parent::_afterSave($object);
    }

    /**
     * Whether the current store is the admin (default) store
     *
     * @return bool
     */
    private function isAdminStore(): bool
    {
        return (int)$this->storeManager->getStore()->getId() === Store::DEFAULT_STORE_ID;
    }

    /**
     * Getting base popularity per store view for specified tag
     *
     * @param int $tagId
     * @return array
     * @deprecated legacy aggregation, kept for parity
     */
    protected function _getExistingBasePopularity($tagId)
    {
        $connection = $this->getConnection();
        $selectSummary = $connection->select()
            ->from(
                ['main' => $this->getTable('tag_summary')],
                ['store_id', 'base_popularity']
            )
            ->where('main.tag_id = :tag_id')
            ->where('main.store_id != 0');

        return $connection->fetchAssoc($selectSummary, ['tag_id' => $tagId]);
    }

    /**
     * Build the relation/store/website joined select shared by aggregations
     *
     * @param array $columns
     * @return \Magento\Framework\DB\Select
     */
    private function getActiveRelationSelect(array $columns)
    {
        return $this->getConnection()->select()
            ->from(['main' => $this->getTable('tag_relation')], $columns)
            ->join(
                ['store' => $this->getTable('store')],
                'store.store_id = main.store_id AND store.store_id > 0',
                []
            )
            ->join(
                ['product_website' => $this->getTable('catalog_product_website')],
                'product_website.website_id = store.website_id AND product_website.product_id = main.product_id',
                []
            )
            ->where('main.tag_id = :tag_id')
            ->where('main.active = 1');
    }

    /**
     * Get aggregation data per store view
     *
     * @param int $tagId
     * @return array
     * @deprecated legacy aggregation, kept for parity
     */
    protected function _getAggregationPerStoreView($tagId)
    {
        $connection = $this->getConnection();
        $selectLocal = $this->getActiveRelationSelect([
            'customers' => 'COUNT(DISTINCT main.customer_id)',
            'products' => 'COUNT(DISTINCT main.product_id)',
            'store_id',
            'uses' => 'COUNT(main.tag_relation_id)',
        ])->group('main.store_id');

        $selectLocalResult = $connection->fetchAll($selectLocal, ['tag_id' => $tagId]);

        $selectHistorical = $this->getActiveRelationSelect([
            'historical_uses' => 'COUNT(main.tag_relation_id)',
            'store_id',
        ])->group('main.store_id');

        $selectHistoricalResult = $connection->fetchAll($selectHistorical, ['tag_id' => $tagId]);

        foreach ($selectHistoricalResult as $historical) {
            foreach ($selectLocalResult as $key => $local) {
                if ($local['store_id'] == $historical['store_id']) {
                    $selectLocalResult[$key]['historical_uses'] = $historical['historical_uses'];
                    break;
                }
            }
        }

        return $selectLocalResult;
    }

    /**
     * Get global aggregation data for row with store_id = 0
     *
     * @param int $tagId
     * @return array
     * @deprecated legacy aggregation, kept for parity
     */
    protected function _getGlobalAggregation($tagId)
    {
        $connection = $this->getConnection();
        $selectGlobal = $this->getActiveRelationSelect([
            'customers' => 'COUNT(DISTINCT main.customer_id)',
            'products' => 'COUNT(DISTINCT main.product_id)',
            'store_id' => new \Zend_Db_Expr('0'),
            'uses' => 'COUNT(main.tag_relation_id)',
        ]);
        $result = $connection->fetchRow($selectGlobal, ['tag_id' => $tagId]);
        if (!$result) {
            return [];
        }

        $selectHistoricalGlobal = $this->getActiveRelationSelect(
            ['historical_uses' => 'COUNT(main.tag_relation_id)']
        );
        $result['historical_uses'] = (int)$connection->fetchOne($selectHistoricalGlobal, ['tag_id' => $tagId]);

        return $result;
    }

    /**
     * Getting statistics data into buffer.
     * Replacing our buffer array with new statistics and incoming data.
     *
     * @param TagModel $object
     * @return TagModel
     * @deprecated legacy aggregation, kept for parity
     */
    public function aggregate($object)
    {
        $tagId = (int)$object->getId();
        $storeId = (int)$object->getStore();

        // create final summary from existing data and add specified base popularity
        $finalSummary = $this->_getExistingBasePopularity($tagId);
        if ($object->hasBasePopularity() && $storeId) {
            $finalSummary[$storeId]['store_id'] = $storeId;
            $finalSummary[$storeId]['base_popularity'] = $object->getBasePopularity();
        }

        // calculate aggregation data
        $summaries = $this->_getAggregationPerStoreView($tagId);
        $summariesGlobal = $this->_getGlobalAggregation($tagId);
        if ($summariesGlobal) {
            $summaries[] = $summariesGlobal;
        }

        // override final summary with aggregated data
        foreach ($summaries as $row) {
            $storeId = (int)$row['store_id'];
            foreach ($row as $key => $value) {
                $finalSummary[$storeId][$key] = $value;
            }
        }

        // prepare static parameters to final summary for insertion
        foreach ($finalSummary as $key => $row) {
            $finalSummary[$key]['tag_id'] = $tagId;
            foreach (['base_popularity', 'popularity', 'historical_uses', 'uses', 'products', 'customers'] as $str) {
                if (!isset($row[$str])) {
                    $finalSummary[$key][$str] = 0;
                }
            }

            $finalSummary[$key]['popularity'] = $finalSummary[$key]['historical_uses'];
        }

        // remove old and insert new data
        $connection = $this->getConnection();
        $connection->delete($this->getTable('tag_summary'), ['tag_id = ?' => $tagId]);
        if ($finalSummary) {
            $connection->insertMultiple($this->getTable('tag_summary'), $finalSummary);
        }

        return $object;
    }

    /**
     * Decrementing tag products quantity as action for product delete
     *
     * @param array $tagsId
     * @return int The number of affected rows
     */
    public function decrementProducts(array $tagsId)
    {
        if ($tagsId === []) {
            return 0;
        }

        return $this->getConnection()->update(
            $this->getTable('tag_summary'),
            ['products' => new \Zend_Db_Expr('products - 1')],
            ['tag_id IN (?)' => $tagsId]
        );
    }

    /**
     * Add summary data to specified object
     *
     * @param TagModel $object
     * @return TagModel
     * @deprecated legacy aggregation, kept for parity
     */
    public function addSummary($object)
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from(['relation' => $this->getTable('tag_relation')], [])
            ->joinLeft(
                ['summary' => $this->getTable('tag_summary')],
                'relation.tag_id = summary.tag_id AND relation.store_id = summary.store_id',
                [
                    'customers',
                    'products',
                    'popularity',
                ]
            )
            ->where('relation.tag_id = :tag_id')
            ->where('relation.store_id = :store_id')
            ->limit(1);
        $bind = [
            'tag_id' => (int)$object->getId(),
            'store_id' => (int)$object->getStoreId(),
        ];
        $row = $connection->fetchRow($select, $bind);
        if ($row) {
            $object->addData($row);
        }

        return $object;
    }

    /**
     * Retrieve select object for load object data
     * Redeclare parent method just for adding tag's base popularity if flag exists
     *
     * @param string $field
     * @param mixed $value
     * @param AbstractModel|TagModel $object
     * @return \Magento\Framework\DB\Select
     */
    protected function _getLoadSelect($field, $value, $object)
    {
        $select = parent::_getLoadSelect($field, $value, $object);
        if ($object->getAddBasePopularity() && $object->hasStoreId()) {
            $select->joinLeft(
                ['properties' => $this->getTable('tag_properties')],
                'properties.tag_id = ' . $this->getMainTable() . '.tag_id AND properties.store_id = '
                . (int)$object->getStoreId(),
                'base_popularity'
            );
        }

        return $select;
    }

    /**
     * Fetch store ids in which tag visible
     *
     * @param AbstractModel|TagModel $object
     * @return $this
     */
    protected function _afterLoad(AbstractModel $object)
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable('tag_summary'), ['store_id'])
            ->where('tag_id = :tag_id');
        $storeIds = $connection->fetchCol($select, ['tag_id' => $object->getId()]);

        $object->setVisibleInStoreIds($storeIds);

        return parent::_afterLoad($object);
    }
}
