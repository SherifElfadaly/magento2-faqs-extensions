<?php

/**
 *
 * @Author              Ngo Quang Cuong <bestearnmoney87@gmail.com>
 * @Date                2016-12-16 02:02:38
 * @Last modified by:   nquangcuong
 * @Last Modified time: 2016-12-18 22:29:12
 */

namespace PHPCuong\Faq\Model\ResourceModel;

use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPCuong\Faq\Model\Config\Source\Urlkey;

class Faq extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    /**
     * Store manager
     *
     * @var StoreManagerInterface
     */
    protected $_storeManager;
    /**
     * Url key
     *
     * @var Urlkey
     */
    protected $_urlKey;

    const FAQ_QUESTION_PATH = 'faq';

    const FAQ_CATEGORY_PATH = 'faq/category';
    /**
     * @param Context $context
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        Urlkey $urlKey,
        $connectionName = null
    ) {
        $this->_urlKey       = $urlKey;
        $this->_storeManager = $storeManager;
        parent::__construct($context, $connectionName);
    }
    /**
     * construct
     * @return void
     */
    protected function _construct()
    {
        $this->_init('phpcuong_faq', 'faq_id');
    }

    /**
     * Method to run after load
     *
     * @param \Magento\Framework\Model\AbstractModel $object
     * @return $this
     */
    protected function _afterLoad(AbstractModel $object)
    {
        $faq_store = $this->getConnection()
            ->select()
            ->from($this->getTable('phpcuong_faq_store'), ['store_id'])
            ->where('faq_id = :faq_id');

        $stores = $this->getConnection()->fetchCol($faq_store, [':faq_id' => $object->getId()]);

        if ($stores) {
            $object->setData('stores', $stores);
        }

        $faq_category = $this->getConnection()
            ->select()
            ->from($this->getTable('phpcuong_faq_category_id'), ['category_id'])
            ->where('faq_id = :faq_id');

        $category = $this->getConnection()->fetchCol($faq_category, [':faq_id' => $object->getId()]);

        if ($category) {
            $object->setData('category_id', $category);
        }

        return parent::_afterLoad($object);
    }

    /**
     * Perform operations before object save
     *
     * @param AbstractModel $object
     * @return $this
     * @throws LocalizedException
     */
    protected function _beforeSave(AbstractModel $object)
    {
        if (empty($object->getData('identifier'))) {
            $identifier = $this->_urlKey->generateIdentifier($object->getTitle());
            $object->setIdentifier($identifier);
        }

        if ($this->duplicateFaqIdentifier($object)) {
            throw new LocalizedException(
                __('URL key for specified store already exists.')
            );
        }

        if ($this->isNumericFaqIdentifier($object)) {
            throw new LocalizedException(
                __('The faq URL key cannot be made of only numbers.')
            );
        }
    }

    /**
     *  Check whether FAQ identifier is duplicate
     *
     * @param AbstractModel $object
     * @return bool
     */
    protected function duplicateFaqIdentifier(AbstractModel $object)
    {
        $stores = $this->getStores($object);

        $select = $this->getConnection()->select()
            ->from(['faq' => $this->getMainTable()])
            ->join(
                ['faq_store' => $this->getTable('phpcuong_faq_store')],
                'faq.faq_id = faq_store.faq_id',
                ['store_id']
            )
            ->where('faq.identifier = ?', $object->getData('identifier'))
            ->where('faq_store.store_id IN (?)', $stores);

        if ($object->getData('faq_id')) {
            $select->where('faq.faq_id <> ?', $object->getData('faq_id'));
        }

        if ($this->getConnection()->fetchRow($select)) {
            return true;
        }

        return false;
    }

    /**
     *  Check whether FAQ identifier is numeric
     *
     * @param AbstractModel $object
     * @return bool
     */
    protected function isNumericFaqIdentifier(AbstractModel $object)
    {
        return preg_match('/^[0-9]+$/', $object->getData('identifier'));
    }

    /**
     * after save callback
     *
     * @param \Magento\Framework\Model\AbstractModel $object
     * @return parent
     */
    protected function _afterSave(AbstractModel $object)
    {
        $this->saveFaqRelation($object);
        return parent::_afterSave($object);
    }

    /**
     *
     * @param $faq_id
     * @return array|boolen
     */
    public function getFaqStore($faq_id = null)
    {
        if (!$faq_id || ($faq_id && (int) $faq_id <= 0)) {
            return false;
        }

        $storeIds = [
            Store::DEFAULT_STORE_ID,
            (int) $this->_storeManager->getStore()->getId()
        ];

        $select = $this->getConnection()->select()
            ->from(['faq' => $this->getMainTable()])
            ->joinLeft(
                ['faq_store' => $this->getTable('phpcuong_faq_store')],
                'faq.faq_id = faq_store.faq_id',
                ['store_id']
            )
            ->where('faq.faq_id = ?', $faq_id)
            ->where('faq.is_active = ?', '1')
            ->where('faq_store.store_id IN (?)', $storeIds)
            ->group('faq.faq_id')
            ->limit(1);

        if ($results = $this->getConnection()->fetchRow($select)) {
            return $results;
        }
        return false;
    }

    /**
     *
     * @param $faq_id
     * @return array|boolen
     */
    public function getFaqCategory($faq_id = null)
    {
        $select = $this->getConnection()->select()
            ->from(['faq' => $this->getMainTable()], ['faq_id'])
            ->joinLeft(
                ['faqcat' => $this->getTable('phpcuong_faq_category_id')],
                'faq.faq_id = faqcat.faq_id',
                ['category_id']
            )
            ->joinLeft(
                ['cat' => $this->getTable('phpcuong_faq_category')],
                'faqcat.category_id = cat.category_id',
                ['title', 'identifier']
            )
            ->where('faq.faq_id = ?', $faq_id)
            ->where('cat.is_active = ?', '1')
            ->group('faq.faq_id')
            ->limit(1);

        if ($results = $this->getConnection()->fetchRow($select)) {
            return $results;
        }
        return false;
    }

    /**
     * @param \Magento\Framework\Model\AbstractModel $object
     * @return array
     */
    protected function getStores(AbstractModel $object)
    {
        if ($this->_storeManager->hasSingleStore()) {
            $stores = [Store::DEFAULT_STORE_ID];
        } else {
            $stores = (array)$object->getData('stores');
        }
        $rs = [];
        $flag = 0;
        foreach ($stores as $store) {
            if ($store == Store::DEFAULT_STORE_ID) {
                $_stores = $this->_storeManager->getStores();
                foreach ($_stores as $value) {
                    if ($value->getData()['is_active']) {
                        $rs[] = $value->getData()['store_id'];
                    }
                }
                break;
            }
        }
        $stores   = array_unique($stores + $rs);
        return $stores;
    }

    /**
     * @param \Magento\Framework\Model\AbstractModel $object
     * @return $this
     */
    protected function saveFaqRelation(AbstractModel $object)
    {
        $category_id = $object->getData('category_id');
        $faq_id = $object->getData('faq_id');

        $stores = $this->getStores($object);

        if ($faq_id && (int) $faq_id > 0) {

            $adapter = $this->getConnection();

            if ($category_id) {

                $condition = ['faq_id = ?' => (int) $faq_id];
                $adapter->delete($this->getTable('phpcuong_faq_category_id'), $condition);

                $faq_category = [
                    'faq_id' => (int) $faq_id,
                    'category_id' => (int) $category_id
                ];
                $adapter->insertMultiple($this->getTable('phpcuong_faq_category_id'), $faq_category);
            }

            if ($stores) {
                $condition = ['faq_id = ?' => (int) $faq_id];
                $adapter->delete($this->getTable('phpcuong_faq_store'), $condition);

                $entity_type  = 'faq-question';

                $url_rewrite_condition = [
                    'entity_id = ?' => (int) $faq_id,
                    'entity_type = ?' => (int) $entity_type,
                ];
                $adapter->delete($this->getTable('url_rewrite'), $url_rewrite_condition);

                $target_path  = 'faq/question/view/faq_id/'.$faq_id;
                $request_path = Faq::FAQ_QUESTION_PATH.'/'.$object->getIdentifier().'.html';

                $data = [];
                $url_rewrite_data = [];
                foreach ($stores as $store_id) {
                    $data[] = [
                        'faq_id' => (int) $faq_id,
                        'store_id' => (int) $store_id
                    ];
                    if ($store_id > 0) {
                        $url_rewrite_data[] = [
                            'entity_type'      => $entity_type,
                            'entity_id'        => (int) $faq_id,
                            'request_path'     => $request_path,
                            'target_path'      => $target_path,
                            'is_autogenerated' => 1,
                            'store_id'         => (int) $store_id
                        ];
                    }
                }
                $adapter->insertMultiple($this->getTable('phpcuong_faq_store'), $data);

                $adapter->insertMultiple($this->getTable('url_rewrite'), $url_rewrite_data);
            }
        }
        return $this;
    }
}