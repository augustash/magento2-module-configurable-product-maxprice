# Augustash_ConfigurableProduct

## Overview:

Changes Magento 2 default convention of showing the lowest price for a configurable product (before being configured) to show the highest possible price and still allow the displayed price to change as the product is configured.



## Installation

In your project's `composer.json` file, add the following lines to the `require` and `repositories` sections:

```js
{
    "require": {
        "augustash/module-configurable-product-maxprice": "dev-master"
    },
    "repositories": {
        "augustash-configurable-product-maxprice": {
            "type": "vcs",
            "url": "https://github.com/augustash/magento2-module-configurable-product-maxprice.git"
        }
    }
}
```
