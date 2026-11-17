<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 *
 * @license Open Software License (OSL 3.0) / Academic Free License (AFL 3.0), see LICENSE.txt and LICENSE_AFL.txt
 */
declare(strict_types=1);

namespace Magento\Tag\Model\ResourceModel\Entity\Customer;

use Magento\Customer\Model\ResourceModel\Customer\Collection as CustomerCollection;

/**
 * Customers collection with their tags (port of Mage_Tag_Model_Entity_Customer_Collection).
 *
 * Legacy reset the collection select after load to fetch tags; here tags are fetched with a dedicated select so
 * the collection select stays reusable. Resulting item data ('tags') is the same.
 */
class Collection extends CustomerCollection
{
    /**
     * Filter by tag id
     *
     * @param int $tagId
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function addTagFilter($tagId)
    {
        $this->joinField('tag_tag_id', 'tag_relation', 'tag_id', 'customer_id=entity_id');
        $this->getSelect()->where($this->_getAttributeTableAlias('tag_tag_id') . '.tag_id=?', $tagId);
        return $this;
    }

    /**
     * Filter by product id
     *
     * @param int $productId
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function addProductFilter($productId)
    {
        $this->joinField('tag_product_id', 'tag_relation', 'product_id', 'customer_id=entity_id');
        $this->getSelect()->where($this->_getAttributeTableAlias('tag_product_id') . '.product_id=?', $productId);
        return $this;
    }

    /**
     * Load collection and attach tags to the items
     *
     * @param bool $printQuery
     * @param bool $logQuery
     * @return $this
     */
    public function load($printQuery = false, $logQuery = false)
    {
        $wasLoaded = $this->isLoaded();
        parent::load($printQuery, $logQuery);
        if (!$wasLoaded) {
            $this->_loadTags($printQuery, $logQuery);
        }
        return $this;
    }

    /**
     * Load tags of the collection customers
     *
     * @param bool $printQuery
     * @param bool $logQuery
     * @return $this
     */
    protected function _loadTags($printQuery = false, $logQuery = false)
    {
        if (empty($this->_items)) {
            return $this;
        }

        $customerIds = [];
        foreach ($this->getItems() as $item) {
            $customerIds[] = $item->getId();
        }

        $connection = $this->getConnection();
        $select = $connection->select()
            ->from(['tr' => $this->getTable('tag_relation')], ['*', 'total_used' => 'count(tr.tag_relation_id)'])
            ->joinLeft(['t' => $this->getTable('tag')], 't.tag_id=tr.tag_id')
            ->group(['tr.customer_id', 't.tag_id'])
            ->where('tr.customer_id in (?)', $customerIds);
        $this->printLogQuery($printQuery, $logQuery, (string)$select);

        $tags = [];
        foreach ($connection->fetchAll($select) as $row) {
            $tags[$row['customer_id']][] = $row;
        }

        foreach ($this->getItems() as $item) {
            if (isset($tags[$item->getId()])) {
                $item->setData('tags', $tags[$item->getId()]);
            }
        }

        return $this;
    }
}
