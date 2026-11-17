<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 *
 * @license Open Software License (OSL 3.0) / Academic Free License (AFL 3.0), see LICENSE.txt and LICENSE_AFL.txt
 */
declare(strict_types=1);

namespace Magento\Tag\Model\ResourceModel\Tag;

use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Tag\Model\ResourceModel\Tag as TagResource;
use Magento\Tag\Model\Tag;
use Magento\Tag\Model\Tag\Relation;

/**
 * Tag collection (port of Mage_Tag_Model_Resource_Tag_Collection)
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Collection extends AbstractCollection
{
    /**
     * Use getFlag('store_filter') & setFlag('store_filter', true) instead.
     *
     * @var bool
     */
    protected $_isStoreFilter = false;

    /**
     * Joined tables
     *
     * @var array
     */
    protected $_joinFlags = [];

    /**
     * Mapping for fields
     *
     * @var array
     */
    protected $_map = [
        'fields' => [
            'tag_id' => 'main_table.tag_id',
        ],
    ];

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(Tag::class, TagResource::class);
    }

    /**
     * Loads collection
     *
     * @param bool $printQuery
     * @param bool $logQuery
     * @return $this
     */
    public function load($printQuery = false, $logQuery = false)
    {
        if ($this->isLoaded()) {
            return $this;
        }

        parent::load($printQuery, $logQuery);
        if ($this->getFlag('add_stores_after')) {
            $this->_addStoresVisibility();
        }

        return $this;
    }

    /**
     * Set flag about joined table. setFlag method must be used in future.
     *
     * @param string $table
     * @return $this
     * @deprecated use setFlag()
     */
    public function setJoinFlag($table)
    {
        $this->setFlag($table, true);
        return $this;
    }

    /**
     * Unset value of join flag. Set false (bool) value to flag instead in future.
     *
     * @param string|null $table
     * @return $this
     * @deprecated use setFlag()
     */
    public function unsetJoinFlag($table = null)
    {
        $this->setFlag($table, false);
        return $this;
    }

    /**
     * Set limit
     *
     * @param int $limit
     * @return $this
     */
    public function limit($limit)
    {
        $this->getSelect()->limit($limit);
        return $this;
    }

    /**
     * Replacing popularity by sum of popularity and base_popularity
     *
     * @param int|null $limit
     * @return $this
     */
    public function addPopularity($limit = null)
    {
        if (!$this->getFlag('popularity')) {
            $this->getSelect()
                ->joinLeft(
                    ['relation' => $this->getTable('tag_relation')],
                    'main_table.tag_id = relation.tag_id',
                    []
                )
                ->joinLeft(
                    ['summary' => $this->getTable('tag_summary')],
                    'relation.tag_id = summary.tag_id AND relation.store_id = summary.store_id',
                    ['popularity']
                )
                ->group('main_table.tag_id');

            if ($limit !== null) {
                $this->getSelect()->limit($limit);
            }

            $this->setFlag('popularity');
        }

        return $this;
    }

    /**
     * Adds summary
     *
     * @param int|array $storeId
     * @return $this
     */
    public function addSummary($storeId)
    {
        if (!$this->getFlag('summary')) {
            $tableAlias = 'summary';
            $joinCondition = $this->getConnection()
                ->quoteInto(' AND ' . $tableAlias . '.store_id IN(?)', $storeId);

            $this->getSelect()
                ->joinLeft(
                    [$tableAlias => $this->getTable('tag_summary')],
                    'main_table.tag_id = ' . $tableAlias . '.tag_id' . $joinCondition,
                    ['store_id', 'popularity', 'customers', 'products']
                );

            $this->addFilterToMap('store_id', $tableAlias . '.store_id');
            $this->addFilterToMap('popularity', $tableAlias . '.popularity');
            $this->addFilterToMap('customers', $tableAlias . '.customers');
            $this->addFilterToMap('products', $tableAlias . '.products');

            $this->setFlag('summary', true);
        }

        return $this;
    }

    /**
     * Adds store visibility
     *
     * @return $this
     */
    public function addStoresVisibility()
    {
        $this->setFlag('add_stores_after', true);
        return $this;
    }

    /**
     * Adds store visibility
     *
     * @return $this
     */
    protected function _addStoresVisibility()
    {
        $tagIds = $this->getColumnValues('tag_id');

        $tagsStores = [];
        if (count($tagIds)) {
            $select = $this->getConnection()->select()
                ->from($this->getTable('tag_summary'), ['store_id', 'tag_id'])
                ->where('tag_id IN(?)', $tagIds);
            $tagsRaw = $this->getConnection()->fetchAll($select);

            foreach ($tagsRaw as $tag) {
                if (!isset($tagsStores[$tag['tag_id']])) {
                    $tagsStores[$tag['tag_id']] = [];
                }

                $tagsStores[$tag['tag_id']][] = $tag['store_id'];
            }
        }

        foreach ($this as $item) {
            if (isset($tagsStores[$item->getId()])) {
                $item->setStores($tagsStores[$item->getId()]);
            } else {
                $item->setStores([]);
            }
        }

        return $this;
    }

    /**
     * Adds field to filter
     *
     * @param string|array $field
     * @param null|array|int|string $condition
     * @return $this
     */
    public function addFieldToFilter($field, $condition = null)
    {
        if ($this->getFlag('relation') && $field == 'popularity') {
            $this->getSelect()->having(
                $this->_getConditionSql('COUNT(relation.tag_relation_id)', $condition)
            );
        } elseif ($this->getFlag('summary')
            && in_array($field, ['customers', 'products', 'uses', 'historical_uses', 'popularity'])
        ) {
            $this->getSelect()->where($this->_getConditionSql('summary.' . $field, $condition));
        } else {
            parent::addFieldToFilter($field, $condition);
        }

        return $this;
    }

    /**
     * Get sql for get record count
     *
     * @return Select
     */
    public function getSelectCountSql()
    {
        $select = parent::getSelectCountSql();

        $select->reset(Select::COLUMNS);
        $select->reset(Select::GROUP);
        $select->reset(Select::HAVING);
        $select->columns('COUNT(DISTINCT main_table.tag_id)');
        return $select;
    }

    /**
     * Add filter by store
     *
     * @param array|int $storeId
     * @param bool $allFilter
     * @return $this
     */
    public function addStoreFilter($storeId, $allFilter = true)
    {
        if (!$this->getFlag('store_filter')) {
            $this->getSelect()->joinLeft(
                ['summary_store' => $this->getTable('tag_summary')],
                'main_table.tag_id = summary_store.tag_id'
            );

            $this->getSelect()->where('summary_store.store_id IN (?)', $storeId);

            $this->getSelect()->group('main_table.tag_id');

            if ($this->getFlag('relation') && $allFilter) {
                $this->getSelect()->where('relation.store_id IN (?)', $storeId);
            }

            if ($this->getFlag('prelation') && $allFilter) {
                $this->getSelect()->where('prelation.store_id IN (?)', $storeId);
            }

            $this->setFlag('store_filter', true);
        }

        return $this;
    }

    /**
     * Adds filtering by active
     *
     * @return $this
     */
    public function setActiveFilter()
    {
        $statusActive = Relation::STATUS_ACTIVE;
        $this->getSelect()->where('relation.active = ?', $statusActive);
        if ($this->getFlag('prelation')) {
            $this->getSelect()->where('prelation.active = ?', $statusActive);
        }

        return $this;
    }

    /**
     * Adds filter by status
     *
     * @param int $status
     * @return $this
     */
    public function addStatusFilter($status)
    {
        $this->getSelect()->where('main_table.status = ?', $status);
        return $this;
    }

    /**
     * Adds filter by product id
     *
     * @param int $productId
     * @return $this
     */
    public function addProductFilter($productId)
    {
        $this->addFieldToFilter('relation.product_id', $productId);
        if ($this->getFlag('prelation')) {
            $this->addFieldToFilter('prelation.product_id', $productId);
        }

        return $this;
    }

    /**
     * Adds filter by customer id
     *
     * @param int $customerId
     * @return $this
     */
    public function addCustomerFilter($customerId)
    {
        $this->getSelect()
            ->where('relation.customer_id = ?', $customerId);
        if ($this->getFlag('prelation')) {
            $this->getSelect()
                ->where('prelation.customer_id = ?', $customerId);
        }

        return $this;
    }

    /**
     * Adds grouping by tag id
     *
     * @return $this
     */
    public function addTagGroup()
    {
        $this->getSelect()->group('main_table.tag_id');
        return $this;
    }

    /**
     * Joins tag_relation table
     *
     * @return $this
     */
    public function joinRel()
    {
        $this->setFlag('relation', true);
        $this->getSelect()->joinLeft(
            ['relation' => $this->getTable('tag_relation')],
            'main_table.tag_id=relation.tag_id'
        );
        return $this;
    }
}
