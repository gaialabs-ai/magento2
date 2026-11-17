<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 *
 * @license Open Software License (OSL 3.0) / Academic Free License (AFL 3.0), see LICENSE.txt and LICENSE_AFL.txt
 */
declare(strict_types=1);

namespace Magento\Tag\Model\ResourceModel\Indexer;

use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Indexer\AbstractIndexer;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\Table\StrategyInterface;
use Magento\Framework\Model\ResourceModel\Db\Context;

/**
 * Tag summary indexer resource model (port of Mage_Tag_Model_Resource_Indexer_Summary).
 *
 * Recalculates tag_summary rows: one row per tag and store plus a global (store_id = 0) row.
 */
class Summary extends AbstractIndexer
{
    /**
     * @param Context $context
     * @param StrategyInterface $tableStrategy
     * @param EavConfig $eavConfig
     * @param EventManager $eventManager
     * @param string|null $connectionName
     * @param MetadataPool|null $metadataPool
     */
    public function __construct(
        Context $context,
        StrategyInterface $tableStrategy,
        EavConfig $eavConfig,
        private readonly EventManager $eventManager,
        $connectionName = null,
        ?MetadataPool $metadataPool = null
    ) {
        parent::__construct($context, $tableStrategy, $eavConfig, $connectionName, $metadataPool);
    }

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init('tag_summary', 'tag_id');
    }

    /**
     * Process tag save
     *
     * @param int|null $tagId
     * @return $this
     */
    public function tagSave($tagId): self
    {
        return empty($tagId) ? $this : $this->aggregate($tagId);
    }

    /**
     * Process tag relation save
     *
     * @param int|null $tagId
     * @return $this
     */
    public function tagRelationSave($tagId): self
    {
        return empty($tagId) ? $this : $this->aggregate($tagId);
    }

    /**
     * Process product save (status, visibility or websites were changed)
     *
     * @param int $productId
     * @return $this
     */
    public function catalogProductSave($productId): self
    {
        $tagIds = $this->getRelatedTagIds([(int)$productId]);

        return $tagIds === [] ? $this : $this->aggregate($tagIds);
    }

    /**
     * Process product delete. Tag ids must be collected before product deletion
     *
     * @param int[] $tagIds
     * @return $this
     */
    public function catalogProductDelete(array $tagIds): self
    {
        return empty($tagIds) ? $this : $this->aggregate($tagIds);
    }

    /**
     * Process product mass action
     *
     * @param int[] $tagIds
     * @return $this
     */
    public function catalogProductMassAction(array $tagIds): self
    {
        return empty($tagIds) ? $this : $this->aggregate($tagIds);
    }

    /**
     * Retrieve tag ids related to specified products
     *
     * @param int[] $productIds
     * @return int[]
     */
    public function getRelatedTagIds(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable('tag_relation'), 'tag_id')
            ->where('product_id IN(?)', $productIds)
            ->order('tag_id');
        return array_values(array_unique(array_map('intval', $connection->fetchCol($select))));
    }

    /**
     * Reindex all tags
     *
     * @return $this
     */
    public function reindexAll()
    {
        return $this->aggregate();
    }

    /**
     * Aggregate tags by specified ids (all tags if ids are empty)
     *
     * @param int[]|int|null $tagIds
     * @return $this
     * @throws \Exception
     */
    public function aggregate($tagIds = null): self
    {
        $connection = $this->getConnection();
        $this->beginTransaction();

        try {
            if (!empty($tagIds)) {
                $connection->delete($this->getTable('tag_summary'), ['tag_id IN(?)' => $tagIds]);
            } else {
                $connection->delete($this->getTable('tag_summary'));
            }

            $select = $connection->select()
                ->from(
                    ['tr' => $this->getTable('tag_relation')],
                    [
                        'tr.tag_id',
                        'tr.store_id',
                        'customers' => 'COUNT(DISTINCT tr.customer_id)',
                        'products' => 'COUNT(DISTINCT tr.product_id)',
                        'popularity' => new \Zend_Db_Expr(
                            'COUNT(tr.customer_id) + MIN('
                            . $connection->getCheckSql(
                                'tp.base_popularity IS NOT NULL',
                                'tp.base_popularity',
                                '0'
                            ) . ')'
                        ),
                        'uses' => new \Zend_Db_Expr('0'),
                        'historical_uses' => new \Zend_Db_Expr('0'),
                        'base_popularity' => new \Zend_Db_Expr('0'),
                    ]
                )
                ->joinInner(['cs' => $this->getTable('store')], 'cs.store_id = tr.store_id', [])
                ->joinInner(
                    ['pw' => $this->getTable('catalog_product_website')],
                    'cs.website_id = pw.website_id AND tr.product_id = pw.product_id',
                    []
                )
                ->joinInner(
                    ['e' => $this->getTable('catalog_product_entity')],
                    'tr.product_id = e.entity_id',
                    []
                )
                ->joinLeft(
                    ['tp' => $this->getTable('tag_properties')],
                    'tp.tag_id = tr.tag_id AND tp.store_id = tr.store_id',
                    []
                )
                ->group(['tr.tag_id', 'tr.store_id'])
                ->where('tr.active = 1');

            $linkField = $this->getMetadataPool()
                ->getMetadata(\Magento\Catalog\Api\Data\ProductInterface::class)
                ->getLinkField();
            $statusCond = $connection->quoteInto('=?', ProductStatus::STATUS_ENABLED);
            $this->_addAttributeToSelect($select, 'status', 'e.' . $linkField, 'cs.store_id', $statusCond);

            $visibilityCond = $connection->quoteInto('!=?', Visibility::VISIBILITY_NOT_VISIBLE);
            $this->_addAttributeToSelect($select, 'visibility', 'e.' . $linkField, 'cs.store_id', $visibilityCond);

            if (!empty($tagIds)) {
                $select->where('tr.tag_id IN(?)', $tagIds);
            }

            $this->eventManager->dispatch(
                'prepare_catalog_product_index_select',
                [
                    'select' => $select,
                    'entity_field' => new \Zend_Db_Expr('e.entity_id'),
                    'website_field' => new \Zend_Db_Expr('cs.website_id'),
                    'store_field' => new \Zend_Db_Expr('cs.store_id'),
                ]
            );

            $connection->query(
                $select->insertFromSelect(
                    $this->getTable('tag_summary'),
                    [
                        'tag_id',
                        'store_id',
                        'customers',
                        'products',
                        'popularity',
                        'uses',
                        'historical_uses',
                        'base_popularity',
                    ]
                )
            );

            $selectedFields = [
                'tag_id' => 'tag_id',
                'store_id' => new \Zend_Db_Expr('0'),
                'customers' => 'COUNT(DISTINCT customer_id)',
                'products' => 'COUNT(DISTINCT product_id)',
                'popularity' => 'COUNT(customer_id)',
                'uses' => new \Zend_Db_Expr('0'),
                'historical_uses' => new \Zend_Db_Expr('0'),
                'base_popularity' => new \Zend_Db_Expr('0'),
            ];

            $aggregateSelect = $connection->select();
            $aggregateSelect->from($this->getTable('tag_relation'), $selectedFields)
                ->group('tag_id')
                ->where('active = 1');

            if (!empty($tagIds)) {
                $aggregateSelect->where('tag_id IN(?)', $tagIds);
            }

            $connection->query(
                $aggregateSelect->insertFromSelect($this->getTable('tag_summary'), array_keys($selectedFields))
            );
            $this->commit();
        } catch (\Exception $exception) {
            $this->rollBack();
            throw $exception;
        }

        return $this;
    }
}
