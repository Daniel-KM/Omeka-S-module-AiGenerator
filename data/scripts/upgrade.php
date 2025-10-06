<?php

declare(strict_types=1);

namespace AiGenerator;

use Common\Stdlib\PsrMessage;
use Omeka\Module\Exception\ModuleCannotInstallException;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\View\Helper\Url $url
 * @var \Laminas\Log\Logger $logger
 * @var \Omeka\Settings\Settings $settings
 * @var \Laminas\I18n\View\Helper\Translate $translate
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Laminas\Mvc\I18n\Translator $translator
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Settings\SiteSettings $siteSettings
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$url = $plugins->get('url');
$api = $plugins->get('api');
$logger = $services->get('Omeka\Logger');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$translator = $services->get('MvcTranslator');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$siteSettings = $services->get('Omeka\Settings\Site');
$entityManager = $services->get('Omeka\EntityManager');

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.73')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.73'
    );
    throw new ModuleCannotInstallException((string) $message);
}

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('AdvancedResourceTemplate', '3.4.43')) {
    $message = new PsrMessage(
        'The module {module} should be upgraded to version {version} or later.', // @translate
        ['module' => 'AdvancedResourceTemplate', 'version' => '3.4.43']
    );
    throw new ModuleCannotInstallException((string) $message);
}

if (version_compare($oldVersion, '3.4.3', '<')) {
    // Fix resource template main option for generatable.
    /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $resourceTemplate */
    $resourceTemplates = $api->search('resource_templates')->getContent();
    foreach ($resourceTemplates as $resourceTemplate) {
        $data = $resourceTemplate->data();
        $generatable = $data['generatable'] ?? null;
        $data['generatable'] = in_array($generatable, ['specifc', 'none']) ? $generatable : 'all';
        if ($data['generatable'] !== $generatable) {
            $api->update('resource_templates', $resourceTemplate->id(), ['o:data' => $data], [], ['isPartial' => true]);
        }
    }
}
