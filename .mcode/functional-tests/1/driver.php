<?php
// Functional driver: boots the real Magento app and exercises Magento_Tag. Usage: php driver.php <action> [arg]
use Magento\Framework\App\Bootstrap;
require dirname(__DIR__, 3) . '/app/bootstrap.php';
$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$action = $argv[1] ?? '';
$out = [];
$conn = $om->get(\Magento\Framework\App\ResourceConnection::class);
$db = $conn->getConnection();
$t = fn($n) => $conn->getTableName($n);
try {
switch ($action) {
case 'helper':
    $h = $om->get(\Magento\Tag\Helper\Data::class);
    $out['extract'] = $h->extractTags("shoes 'red shirt'  blue\ngreen");
    $out['clean'] = array_values($h->cleanTags(["'a b'", "  x ", "", "''", "c"]));
    $out['statuses'] = $h->getStatusesArray();
    $out['options'] = $h->getStatusesOptionsArray();
    break;
case 'scenario':
    $run = $argv[2];
    $objs = [];
    $reg = $om->get(\Magento\Framework\Registry::class);
    $reg->register('isSecureArea', true, true);
    // product
    $p = $om->create(\Magento\Catalog\Model\Product::class);
    $p->setTypeId('simple')->setAttributeSetId(4)->setSku("ftrun_$run")->setName("ftrun $run")->setPrice(10)
      ->setStatus(1)->setVisibility(4)->setWebsiteIds([1])->setStockData(['qty'=>10,'is_in_stock'=>1]);
    $p->save();
    $pid = (int)$p->getId();
    // customer
    $c = $om->create(\Magento\Customer\Model\Customer::class);
    $c->setWebsiteId(1)->setStoreId(1)->setEmail("ftrun_$run@example.com")->setFirstname('Ft')->setLastname('Run')->setPassword('Passw0rd!x1');
    $c->save();
    $cid = (int)$c->getId();
    $out['product_id'] = $pid; $out['customer_id'] = $cid;
    try {
        $tagF = fn() => $om->create(\Magento\Tag\Model\Tag::class);
        $tag = $tagF();
        $tag->setName("ftrun_$run")->setStatus(\Magento\Tag\Model\Tag::STATUS_APPROVED)->setFirstCustomerId($cid)->setFirstStoreId(1);
        $tag->save();
        $tid = (int)$tag->getId();
        $out['tag_id'] = $tid;
        $out['tag_row'] = $db->fetchRow("SELECT * FROM {$t('tag')} WHERE tag_id=$tid");
        // load by name
        $l = $tagF(); $l->loadByName("ftrun_$run");
        $out['loadByName_id'] = (int)$l->getTagId();
        // relation
        $res = $tag->saveRelation($pid, $cid, 1);
        $out['saveRelation_result'] = $res;
        $res2 = $tag->saveRelation($pid, $cid, 1);
        $out['saveRelation_again'] = $res2;
        $out['relation_rows'] = $db->fetchAll("SELECT tag_id,customer_id,product_id,store_id,active FROM {$t('tag_relation')} WHERE tag_id=$tid");
        $out['related_product_ids'] = $tag->getRelatedProductIds();
        // collections
        $tc = $om->create(\Magento\Tag\Model\ResourceModel\Tag\Collection::class);
        $tc->joinRel()->addProductFilter($pid);
        $out['tagcoll_product'] = array_values(array_map(fn($i)=>$i->getName(), $tc->getItems()));
        $tc2 = $om->create(\Magento\Tag\Model\ResourceModel\Tag\Collection::class);
        $tc2->joinRel()->addCustomerFilter($cid);
        $out['tagcoll_customer'] = array_values(array_map(fn($i)=>$i->getName(), $tc2->getItems()));
        $tc3 = $om->create(\Magento\Tag\Model\ResourceModel\Tag\Collection::class);
        $tc3->addStatusFilter(\Magento\Tag\Model\Tag::STATUS_APPROVED)->addFieldToFilter('name', "ftrun_$run");
        $out['tagcoll_status_count'] = count($tc3->getItems());
        $pc = $om->create(\Magento\Tag\Model\ResourceModel\Product\Collection::class);
        $pc->addTagFilter($tid);
        $out['prodcoll_ids'] = array_values(array_map(fn($i)=>(int)$i->getId(), $pc->getItems()));
        $cc = $om->create(\Magento\Tag\Model\ResourceModel\Customer\Collection::class);
        $cc->addTagFilter($tid);
        $out['custcoll_ids'] = array_values(array_map(fn($i)=>(int)$i->getId(), $cc->getItems()));
        $rc = $om->create(\Magento\Tag\Model\ResourceModel\Tag\Relation\Collection::class);
        $rc->addTagFilter($tid)->addActiveFilter();
        $out['relcoll_count'] = count($rc->getItems());
        $pop = $om->create(\Magento\Tag\Model\ResourceModel\Popular\Collection::class);
        $pop->joinFields(1);
        $out['popular_loaded'] = is_array($pop->getItems());
        // indexer
        $db->delete($t('tag_summary'), ['tag_id = ?' => $tid]);
        $idx = $om->get(\Magento\Tag\Model\Indexer\Summary::class);
        $idx->executeFull();
        $out['summary_rows_after_full'] = $db->fetchAll("SELECT * FROM {$t('tag_summary')} WHERE tag_id=$tid");
        $db->delete($t('tag_summary'), ['tag_id = ?' => $tid]);
        $idx->executeRow($tid);
        $out['summary_rows_after_row'] = $db->fetchAll("SELECT * FROM {$t('tag_summary')} WHERE tag_id=$tid");
        // deactivate
        $rel = $om->create(\Magento\Tag\Model\Tag\Relation::class);
        $rel->setTagId($tid)->setCustomerId($cid)->deactivate();
        $out['active_after_deactivate'] = $db->fetchCol("SELECT active FROM {$t('tag_relation')} WHERE tag_id=$tid");
        // delete tag -> cascade
        $tag->delete();
        $out['relations_after_tag_delete'] = (int)$db->fetchOne("SELECT COUNT(*) FROM {$t('tag_relation')} WHERE tag_id=$tid");
    } finally {
        $db->delete($t('tag'), ['name = ?' => "ftrun_$run"]);
        $p->delete(); $c->delete();
    }
    break;
}
} catch (\Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
    $out['trace'] = substr($e->getTraceAsString(), 0, 1500);
}
echo "\n@@JSON@@" . json_encode($out) . "\n";
