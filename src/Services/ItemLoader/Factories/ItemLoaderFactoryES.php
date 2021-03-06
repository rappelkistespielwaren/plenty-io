<?php
namespace IO\Services\ItemLoader\Factories;

use IO\Services\ItemLoader\Contracts\ItemLoaderContract;
use IO\Services\ItemLoader\Contracts\ItemLoaderFactory;
use IO\Services\ItemLoader\Contracts\ItemLoaderPaginationContract;
use IO\Services\ItemLoader\Contracts\ItemLoaderSortingContract;
use IO\Services\SalesPriceService;
use Plenty\Modules\Cloud\ElasticSearch\Lib\Search\Document\DocumentSearch;
use Plenty\Modules\Cloud\ElasticSearch\Lib\Sorting\SortingInterface;
use Plenty\Modules\Cloud\ElasticSearch\Lib\Source\IncludeSource;
use Plenty\Modules\Item\Search\Contracts\VariationElasticSearchSearchRepositoryContract;
use Plenty\Modules\Item\SalesPrice\Models\SalesPriceSearchResponse;

/**
 * Created by ptopczewski, 09.01.17 08:35
 * Class ItemLoaderFactoryES
 * @package IO\Services\ItemLoader\Factories
 */
class ItemLoaderFactoryES implements ItemLoaderFactory
{
	/**
	 * @param array $loaderClassList
	 * @param array $resultFields
	 * @param array $options
	 * @return array
	 */
	public function runSearch($loaderClassList, $resultFields,  $options = [])
	{
		/** @var VariationElasticSearchSearchRepositoryContract $elasticSearchRepo */
		$elasticSearchRepo = pluginApp(VariationElasticSearchSearchRepositoryContract::class);

		foreach($loaderClassList as $loaderClass)
		{
			/** @var ItemLoaderContract $loader */
			$loader = pluginApp($loaderClass);
            
            if($loader instanceof ItemLoaderContract)
            {
                //search, filter
                $search = $loader->getSearch();
                foreach($loader->getFilterStack($options) as $filter)
                {
                    $search->addFilter($filter);
                }
            }

			//sorting
			if($loader instanceof ItemLoaderSortingContract)
			{
				/** @var ItemLoaderSortingContract $loader */
				$sorting = $loader->getSorting($options);
				if($sorting instanceof SortingInterface)
				{
					$search->setSorting($sorting);
				}
			}

			if($loader instanceof ItemLoaderPaginationContract)
			{
				if($search instanceof DocumentSearch)
				{
					/** @var ItemLoaderPaginationContract $loader */
					$search->setPage($loader->getCurrentPage($options), $loader->getItemsPerPage($options));
				}
			}

			/** @var IncludeSource $source */
			$source = pluginApp(IncludeSource::class);

			$currentFields = $resultFields;
			if(array_key_exists($loaderClass, $currentFields))
			{
				$currentFields = $currentFields[$loaderClass];
			}

			$fieldsFound = false;
			foreach($currentFields as $fieldName)
			{
				$source->activateList([$fieldName]);
				$fieldsFound = true;
			}

			if(!$fieldsFound)
			{
				$source->activateAll();
			}

			$search->addSource($source);

			$elasticSearchRepo->addSearch($search);
		}
        
        $result = $elasticSearchRepo->execute();
        
        if(count($result['documents']))
        {
            /**
             * @var SalesPriceService $salesPriceService
             */
            $salesPriceService = pluginApp(SalesPriceService::class);
            
            foreach($result['documents'] as $key => $variation)
            {
                $quantity = 1;
                if(isset($options['basketVariationQuantities'][$variation['data']['variation']['id']]) && (int)$options['basketVariationQuantities'][$variation['data']['variation']['id']] > 0)
                {
                    $quantity = (int)$options['basketVariationQuantities'][$variation['data']['variation']['id']];
                }
                
                $salesPrice = $salesPriceService->getSalesPriceForVariation($variation['data']['variation']['id'], 'default', $quantity);
                if($salesPrice instanceof SalesPriceSearchResponse)
                {
                    $variation['data']['calculatedPrices']['default'] = $salesPrice;
                }
                
                $rrp = $salesPriceService->getSalesPriceForVariation($variation['data']['variation']['id'], 'rrp');
                if($rrp instanceof SalesPriceSearchResponse)
                {
                    $variation['data']['calculatedPrices']['rrp'] = $rrp;
                }
    
                $specialOffer = $salesPriceService->getSalesPriceForVariation($variation['data']['variation']['id'], 'specialOffer');
                if($specialOffer instanceof SalesPriceSearchResponse)
                {
                    $variation['data']['calculatedPrices']['specialOffer'] = $specialOffer;
                }
    
                $result['documents'][$key] = $variation;
            }
        }
        
        return $result;
	}
}