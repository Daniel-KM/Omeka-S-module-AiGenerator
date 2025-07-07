<?php

declare(strict_types=1);

namespace AiGenerator\Service\Form;

use AiGenerator\Form\QuickSearchForm;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class QuickSearchFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $urlHelper = $services->get('ViewHelperManager')->get('url');

        $form = new QuickSearchForm(null, $options ?? []);
        return $form
            ->setUrlHelper($urlHelper);
    }
}
