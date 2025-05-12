<?php declare(strict_types=1);

namespace Generate\Mvc\Controller\Plugin;

use Common\Stdlib\EasyMeta;
use Laminas\I18n\Translator\TranslatorInterface;
use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Manager as ApiManager;
use Omeka\Settings\Settings;
use Generate\Entity\GeneratedResource;

class GenerateViaChatgpt extends AbstractPlugin
{
    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Common\Stdlib\EasyMeta
     */
    protected $easyMeta;

    /**
     * @var \Laminas\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;

    /**
     * @var \Laminas\I18n\Translator\TranslatorInterface
     */
    protected $translator;

    /**
     * @var string
     */
    protected $apiKey;

    public function __construct(
        ApiManager $api,
        EasyMeta $easyMeta,
        LoggerInterface $logger,
        Settings $settings,
        TranslatorInterface $translator,
        string $apiKey
    ) {
        $this->api = $api;
        $this->easyMeta = $easyMeta;
        $this->logger = $logger;
        $this->settings = $settings;
        $this->translator = $translator;
        $this->apiKey = $apiKey;
    }

    /**
     * Generate resource metadata via ChatGPT.
     *
     * @see https://packagist.org/packages/chatgptcom/chatgpt-php
     */
    public function __invoke(array $resourceData, array $options = []): ?GeneratedResource
    {
        if (!$this->apiKey) {
            $this->logger->err('ChatGPT api key is undefined.'); // @translate
            return null;
        }

        $options += [
            'prompt' => '',
        ];
        $this->logger->err('ChatGPT api key is undefined.'); // @translate

        if (empty($options['prompt'])) {
            $prompt = trim((string) $this->settings->get('generate_chatgpt_prompt'));
            if (!$prompt) {
                $configModule = include dirname(__DIR__, 4) . '/config/module.config.php';
                $prompt = $configModule['generate']['settings']['generate_chatgpt_prompt'];
                $this->logger->err('ChatGPT api key is undefined.'); // @translate
                return null;
            }
        }

        return null;
    }
}
