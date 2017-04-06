<?php

namespace Augustash\ConfigurableProduct\Pricing;

use Magento\Framework\Logger\Monolog as MonologLogger;

/**
 * Changes Magento 2 default convention of showing the lowest price
 * for a configurable product (before being configured) to show the
 * highest possible price and still allow the displayed price to
 * change as the product is configured.
 *
 * @see http://magento.stackexchange.com/a/136065
 */


class MaxConfigurablePrice
{
    protected $logger;
    protected $productRepository;
    protected $productFactory;
    protected $dataObjectHelper;

    public function __construct(
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Magento\Catalog\Api\Data\ProductInterfaceFactory $productFactory,
        \Magento\Framework\Api\DataObjectHelper $dataObjectHelper,
        MonologLogger $logger
    )
    {
        $this->productFactory = $productFactory;
        $this->productRepository = $productRepository;
        $this->dataObjectHelper = $dataObjectHelper;
        $this->logger = $logger;
    }

    /**
     * @param \Magento\Framework\Pricing\SaleableInterface|\Magento\Catalog\Model\Product $subject
     * @param callable $proceed
     * @param \Magento\Framework\Pricing\SaleableInterface $product
     * @return float
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function aroundResolvePrice($subject, callable $proceed, \Magento\Framework\Pricing\SaleableInterface $product)
    {
        // let the before hooks run.
        $returnValue = (float)$proceed($product);

        // and then we'll override with our logic
        $price = null;
        //get parent product id
        $parentId = $product['entity_id'];
        $childObj = $this->getChildProductObj($parentId);
        foreach($childObj as $childs){
            $productPrice = $childs->getPrice();
            $price = $price ? max($price, $productPrice) : $productPrice;
        }
        return $price;
    }

    public function getProductInfo($id)
    {
        //get product obj using api repository...
        if(is_numeric($id)){
            return $this->productRepository->getById($id);
        } else {
            return;
        }
    }

    public function getChildProductObj($id)
    {
        $product = $this->getProductInfo($id);
        // if product with no proper id then return null and exit;
        if(!isset($product)){
            return;
        }

        if ($product->getTypeId() != \Magento\ConfigurableProduct\Model\Product\Type\Configurable::TYPE_CODE) {
            return [];
        }

        /**
         * @todo get rid of this magic number BS
         * @var integer
         */
        $storeId = 1; //$this->_storeManager->getStore()->getId();
        $productTypeInstance = $product->getTypeInstance();
        $productTypeInstance->setStoreFilter($storeId, $product);
        $childrenList = [];

        foreach ($productTypeInstance->getUsedProducts($product) as $child) {
            $attributes = [];
            $isSaleable = $child->isSaleable();

            //get only in stock product info
            if($isSaleable){
                foreach ($child->getAttributes() as $attribute) {
                    $attrCode = $attribute->getAttributeCode();
                    $value = $child->getDataUsingMethod($attrCode) ?: $child->getData($attrCode);
                    if (null !== $value && $attrCode != 'entity_id') {
                        $attributes[$attrCode] = $value;
                    }
                }

                $attributes['store_id'] = $child->getStoreId();
                $attributes['id'] = $child->getId();
                /**
                 * @var \Magento\Catalog\Api\Data\ProductInterface $productDataObject
                 */
                $productDataObject = $this->productFactory->create();
                $this->dataObjectHelper->populateWithArray(
                    $productDataObject,
                    $attributes,
                    '\Magento\Catalog\Api\Data\ProductInterface'
                );
                $childrenList[] = $productDataObject;
            }
        }

        $childConfigData = array();
        foreach($childrenList as $child){
            $childConfigData[] = $child;
        }

        return $childConfigData;
    }

    protected function log($message, $methodName = null, $lineNumber = null)
    {
        switch (true) {
            case (!empty($methodName) && !empty($lineNumber)):
                $this->logger->addDebug('FROM ' . __CLASS__ . '::' . $methodName . ' AT LINE ' . $lineNumber);
                $this->logger->addDebug($message);
                break;

            case (empty($methodName) && !empty($lineNumber)):
                $this->logger->addDebug('FROM ' . __CLASS__ . ' AT LINE ' . $lineNumber);
                $this->logger->addDebug($message);
                break;

            case (!empty($methodName) && empty($lineNumber)):
                $this->logger->addDebug('FROM ' . __CLASS__ . '::' . $methodName);
                $this->logger->addDebug($message);
                break;

            default:
                $this->logger->addDebug('FROM ' . __CLASS__);
                $this->logger->addDebug($message);
                break;
        }
    }

 }
