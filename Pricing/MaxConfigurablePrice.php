<?php

namespace Augustash\ConfigurableProduct\Pricing;

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
    protected $productRepository;
    protected $productFactory;
    protected $dataObjectHelper;
    protected $storeManager;
    protected $logFilePath = '/var/log/aai_debug.log';

    public function __construct(
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Magento\Catalog\Api\Data\ProductInterfaceFactory $productFactory,
        \Magento\Framework\Api\DataObjectHelper $dataObjectHelper,
        \Magento\Store\Model\StoreManagerInterface $storeManager
    )
    {
        $this->productFactory = $productFactory;
        $this->productRepository = $productRepository;
        $this->dataObjectHelper = $dataObjectHelper;
        $this->storeManager = $storeManager;
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
        foreach($childObj as $childProduct){
            $productPrice = $childProduct->getPrice();
            $specialPrice = $childProduct->getData('special_price');

            $price = $price ? max($price, $productPrice) : $productPrice;

            // if the product with the highest price is also
            // the product that has a special price make sure
            // to display the special price (assuming it's valid)
            if ($price == $productPrice && isset($specialPrice)) {
                $now = date('Y-m-d H:i:s', time());
                $specialFromDate = $childProduct->getData('special_from_date');
                $specialToDate = $childProduct->getData('special_to_date');

                $this->log('FROM ' . __CLASS__ . '::' . __FUNCTION__ . ' AT LINE ' . __LINE__);
                $this->log('$productPrice: ' . var_export($productPrice, true));
                $this->log('$specialPrice: ' . var_export($specialPrice, true));
                $this->log('$specialFromDate: ' . var_export($specialFromDate, true));
                $this->log('$specialToDate: ' . var_export($specialToDate, true));
                $this->log('$now: ' . var_export($now, true));

                // $this->log('child product debug: ' . print_r($childProduct->debug(), true));


                switch (true) {
                    case (isset($specialFromDate) && isset($specialToDate)):
                        if (($now > $specialFromDate) && ($now < $specialToDate)) {
                            $price = $specialPrice;
                        }
                        break;

                    case (isset($specialFromDate) && !isset($specialToDate)):
                        if ($now > $specialFromDate) {
                            $price = $specialPrice;
                        }
                        break;

                    case (!isset($specialFromDate) && isset($specialToDate)):
                        if ($now < $specialToDate) {
                            $price = $specialPrice;
                        }
                        break;

                    case (!isset($specialFromDate) && !isset($specialToDate)):
                        $price = $specialPrice;
                        break;

                    default:
                        // do nothing...leave $price as it is
                        break;
                }
            }
        }

        $this->log('FROM ' . __CLASS__ . '::' . __FUNCTION__ . ' AT LINE ' . __LINE__);
        $this->log('returned $price: ' . var_export($price, true));

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

        $storeId = $this->getCurrentStoreId();
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

    public function getCurrentStoreId()
    {
        return $this->storeManager->getStore()->getStoreId();
    }

    public function log($info)
    {
        $writer = new \Zend\Log\Writer\Stream(BP . $this->logFilePath);
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $logger->info($info);
    }

 }
