<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model\ResourceModel\Tag;

use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Tag\Model\ResourceModel\Tag as TagResource;
use Magento\Tag\Model\Tag as TagModel;
use Magento\Tag\Model\Tag\Relation as TagRelation;

/**
 * Tag collection.
 */
class Collection extends AbstractCollection
{
    /**
     * @var array
     */
    protected $_map = [
        'fields' => [
            'tag_id' => 'main_table.tag_id',
        ],
    ];

    /**
     * @var array
     */
    protected $_joinFlags = [];

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(TagModel::class, TagResource::class);
    }

    /**
     * @inheritDoc
     */
    public function load($printQuery = false, $logQuery = false)
    {
        if ($this->isLoaded()) {
            return $this;
        }
        parent::load($printQuery, $logQuery);
        if ($this->getFlag('add_stores_after')) {
            $this->addStoresVisibilityInternal();
        }
        return $this;
    }

    /**
     * @param int $limit
     * @return $this
     */
    public function limit(int $limit): self
    {
        $this->getSelect()->limit($limit);
        return $this;
    }

    /**
     * Add popularity summary column.
     *
     * @param int|null $limit
     * @return $this
     */
    public function addPopularity(?int $limit = null): self
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

            $this->_useAnalyticFunction = true;

            if ($limit !== null) {
                $this->getSelect()->limit($limit);
            }
            $this->setFlag('popularity', true);
        }
        return $this;
    }

    /**
     * Add summary joins.
     *
     * @param int|array $storeId
     * @return $this
     */
    public function addSummary($storeId): self
    {
        if (!$this->getFlag('summary')) {
            $tableAlias = 'summary';
            $joinCondition = $this->getConnection()
                ->quoteInto(' AND ' . $tableAlias . '.store_id IN(?)', $storeId);
            $this->getSelect()->joinLeft(
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
     * @return $this
     */
    public function addStoresVisibility(): self
    {
        $this->setFlag('add_stores_after', true);
        return $this;
    }

    /**
     * @return void
     */
    protected function addStoresVisibilityInternal(): void
    {
        $tagIds = $this->getColumnValues('tag_id');
        $tagsStores = [];
        if (count($tagIds)) {
            $select = $this->getConnection()->select()
                ->from($this->getTable('tag_summary'), ['store_id', 'tag_id'])
                ->where('tag_id IN(?)', $tagIds);
            foreach ($this->getConnection()->fetchAll($select) as $row) {
                if (!isset($tagsStores[$row['tag_id']])) {
                    $tagsStores[$row['tag_id']] = [];
                }
                $tagsStores[$row['tag_id']][] = (int) $row['store_id'];
            }
        }
        foreach ($this as $item) {
            $item->setData('stores', $tagsStores[$item->getId()] ?? []);
        }
    }

    /**
     * @inheritDoc
     */
    public function addFieldToFilter($field, $condition = null)
    {
        if ($this->getFlag('relation') && $field === 'popularity') {
            $this->getSelect()->having(
                $this->_getConditionSql('COUNT(relation.tag_relation_id)', $condition)
            );
        } elseif ($this->getFlag('summary')
            && in_array($field, ['customers', 'products', 'uses', 'historical_uses', 'popularity'], true)
        ) {
            $this->getSelect()->where($this->_getConditionSql('summary.' . $field, $condition));
        } else {
            parent::addFieldToFilter($field, $condition);
        }
        return $this;
    }

    /**
     * @inheritDoc
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
     * @param array|int $storeId
     * @param bool $allFilter
     * @return $this
     */
    public function addStoreFilter($storeId, bool $allFilter = true): self
    {
        if (!$this->getFlag('store_filter')) {
            $this->getSelect()->joinLeft(
                ['summary_store' => $this->getTable('tag_summary')],
                'main_table.tag_id = summary_store.tag_id',
                []
            );
            $this->getSelect()->where('summary_store.store_id IN (?)', $storeId);
            $this->getSelect()->group('main_table.tag_id');

            if ($this->getFlag('relation') && $allFilter) {
                $this->getSelect()->where('relation.store_id IN (?)', $storeId);
            }
            if ($this->getFlag('prelation') && $allFilter) {
                $this->getSelect()->where('prelation.store_id IN (?)', $storeId);
            }
            $this->_useAnalyticFunction = true;
            $this->setFlag('store_filter', true);
        }
        return $this;
    }

    /**
     * @return $this
     */
    public function setActiveFilter(): self
    {
        $active = TagRelation::STATUS_ACTIVE;
        $this->getSelect()->where('relation.active = ?', $active);
        if ($this->getFlag('prelation')) {
            $this->getSelect()->where('prelation.active = ?', $active);
        }
        return $this;
    }

    /**
     * @param int $status
     * @return $this
     */
    public function addStatusFilter(int $status): self
    {
        $this->getSelect()->where('main_table.status = ?', $status);
        return $this;
    }

    /**
     * @param int $productId
     * @return $this
     */
    public function addProductFilter(int $productId): self
    {
        $this->addFieldToFilter('relation.product_id', $productId);
        if ($this->getFlag('prelation')) {
            $this->addFieldToFilter('prelation.product_id', $productId);
        }
        return $this;
    }

    /**
     * @param int $customerId
     * @return $this
     */
    public function addCustomerFilter(int $customerId): self
    {
        $this->getSelect()->where('relation.customer_id = ?', $customerId);
        if ($this->getFlag('prelation')) {
            $this->getSelect()->where('prelation.customer_id = ?', $customerId);
        }
        return $this;
    }

    /**
     * @return $this
     */
    public function addTagGroup(): self
    {
        $this->getSelect()->group('main_table.tag_id');
        $this->_useAnalyticFunction = true;
        return $this;
    }

    /**
     * @return $this
     */
    public function joinRel(): self
    {
        $this->setFlag('relation', true);
        $this->getSelect()->joinLeft(
            ['relation' => $this->getTable('tag_relation')],
            'main_table.tag_id=relation.tag_id',
            []
        );
        return $this;
    }
}
