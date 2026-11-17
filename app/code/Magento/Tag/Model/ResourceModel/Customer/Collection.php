<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 *
 * @license Open Software License (OSL 3.0) / Academic Free License (AFL 3.0), see LICENSE.txt and LICENSE_AFL.txt
 */
declare(strict_types=1);

namespace Magento\Tag\Model\ResourceModel\Customer;

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Customer\Model\ResourceModel\Customer\Collection as CustomerCollection;
use Magento\Framework\DB\Select;

/**
 * Tags customer collection (port of Mage_Tag_Model_Resource_Customer_Collection)
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Collection extends CustomerCollection
{
    /**
     * Allows disabling grouping
     *
     * @var bool
     */
    protected $_allowDisableGrouping = true;

    /**
     * Count attribute for count sql
     *
     * @var string
     */
    protected $_countAttribute = 'tr.tag_id';

    /**
     * Array with joined tables
     *
     * @var array
     */
    protected $_joinFlags = [];

    /**
     * Prepare select
     *
     * @return $this
     */
    protected function _initSelect()
    {
        parent::_initSelect();
        $this->_joinFields();
        $this->_setIdFieldName('tag_relation_id');
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
     * Get flag's status about joined table. getFlag method must be used in future.
     *
     * @param string $table
     * @return bool
     * @deprecated use getFlag()
     */
    public function getJoinFlag($table)
    {
        return $this->getFlag($table);
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
     * Adds filter by tag id
     *
     * @param int $tagId
     * @return $this
     */
    public function addTagFilter($tagId)
    {
        $this->getSelect()->where('tr.tag_id = ?', $tagId);
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
        $this->getSelect()->where('tr.product_id = ?', $productId);
        return $this;
    }

    /**
     * Apply filter by store id(s).
     *
     * @param array|int $storeId
     * @return $this
     */
    public function addStoreFilter($storeId)
    {
        $this->getSelect()->where('tr.store_id IN (?)', $storeId);
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
        $this->getSelect()->where('t.status = ?', $status);
        return $this;
    }

    /**
     * Adds desc order by tag relation id
     *
     * @return $this
     */
    public function addDescOrder()
    {
        $this->getSelect()->order('tr.tag_relation_id desc');
        return $this;
    }

    /**
     * Adds grouping by tag id
     *
     * @return $this
     */
    public function addGroupByTag()
    {
        $this->getSelect()->group('tr.tag_id');

        $this->_allowDisableGrouping = true;
        return $this;
    }

    /**
     * Adds grouping by customer id
     *
     * @return $this
     */
    public function addGroupByCustomer()
    {
        $this->getSelect()->group('tr.customer_id');

        $this->_allowDisableGrouping = false;
        return $this;
    }

    /**
     * Disables grouping
     *
     * @return $this
     */
    public function addGroupByCustomerProduct()
    {
        // Nothing need to group
        $this->_allowDisableGrouping = false;
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
        $this->getSelect()->where('tr.customer_id = ?', $customerId);
        return $this;
    }

    /**
     * Joins tables to select
     *
     * @return void
     */
    protected function _joinFields()
    {
        $tagRelationTable = $this->getTable('tag_relation');
        $tagTable = $this->getTable('tag');

        $this->addAttributeToSelect('firstname')
            ->addAttributeToSelect('middlename')
            ->addAttributeToSelect('lastname')
            ->addAttributeToSelect('email');

        $this->getSelect()
            ->join(
                ['tr' => $tagRelationTable],
                'tr.customer_id = e.entity_id',
                ['tag_relation_id', 'product_id', 'active', 'added_in' => 'store_id']
            )
            ->join(['t' => $tagTable], 't.tag_id = tr.tag_id', ['*']);
    }

    /**
     * Gets number of rows
     *
     * @return Select
     */
    public function getSelectCountSql()
    {
        $countSelect = parent::getSelectCountSql();

        if ($this->_allowDisableGrouping) {
            $countSelect->reset(Select::COLUMNS);
            $countSelect->reset(Select::GROUP);
            $countSelect->columns('COUNT(DISTINCT ' . $this->getCountAttribute() . ')');
        }

        return $countSelect;
    }

    /**
     * Adds Product names to item
     *
     * @return $this
     */
    public function addProductName()
    {
        $productsId = [];
        $productsSku = [];
        $productsData = [];

        foreach ($this->getItems() as $item) {
            $productsId[] = $item->getProductId();
        }

        $productsId = array_unique($productsId);

        if ($productsId === []) {
            return $this;
        }

        /** @var ProductCollection $collection */
        $collection = $this->_entityFactory->create(ProductCollection::class);
        $collection->addAttributeToSelect('name')
            ->addAttributeToSelect('sku')
            ->addIdFilter($productsId);

        $collection->load();

        foreach ($collection->getItems() as $item) {
            $productsData[$item->getId()] = $item->getName();
            $productsSku[$item->getId()] = $item->getSku();
        }

        foreach ($this->getItems() as $item) {
            $item->setProduct($productsData[$item->getProductId()] ?? null);
            $item->setProductSku($productsSku[$item->getProductId()] ?? null);
        }

        return $this;
    }

    /**
     * Adds Product names to select
     *
     * @return $this
     */
    public function addProductToSelect()
    {
        // add product attributes to select
        foreach (['name' => 'value'] as $field => $fieldName) {
            $attr = $this->_eavConfig->getAttribute(\Magento\Catalog\Model\Product::ENTITY, $field);
            $this->_select->joinLeft(
                [$field => $attr->getBackend()->getTable()],
                'tr.product_id = ' . $field . '.entity_id AND ' . $field . '.attribute_id = ' . $attr->getId(),
                ['product_' . $field => $fieldName]
            );
        }

        // add product fields
        $this->_select->joinLeft(
            ['p' => $this->getTable('catalog_product_entity')],
            'tr.product_id = p.entity_id',
            ['product_sku' => 'sku']
        );

        return $this;
    }

    /**
     * Sets attribute for count
     *
     * @param string $value
     * @return $this
     */
    public function setCountAttribute($value)
    {
        $this->_countAttribute = $value;
        return $this;
    }

    /**
     * Gets attribute for count
     *
     * @return string
     */
    public function getCountAttribute()
    {
        return $this->_countAttribute;
    }

    /**
     * Adds field to filter
     *
     * @param string|array $attribute
     * @param array|int|string|null $condition
     * @return $this
     */
    public function addFieldToFilter($attribute, $condition = null)
    {
        if ($attribute == 'name') {
            $where = $this->_getConditionSql('t.name', $condition);
            $this->getSelect()->where($where, null, Select::TYPE_CONDITION);
            return $this;
        }

        return parent::addFieldToFilter($attribute, $condition);
    }

    /**
     * Treat "order by" items as attributes to sort
     *
     * @return $this
     */
    protected function _renderOrders()
    {
        if (!$this->_isOrdersRendered) {
            parent::_renderOrders();

            $orders = $this->getSelect()->getPart(Select::ORDER);

            $appliedOrders = [];
            foreach ($orders as $order) {
                $appliedOrders[$order[0]] = true;
            }

            foreach ($this->_orders as $field => $direction) {
                if (empty($appliedOrders[$field])) {
                    $this->_select->order(new \Zend_Db_Expr($field . ' ' . $direction));
                }
            }
        }

        return $this;
    }
}
