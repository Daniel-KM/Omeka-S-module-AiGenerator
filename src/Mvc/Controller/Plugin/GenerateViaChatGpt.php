<?php declare(strict_types=1);

namespace Generate\Mvc\Controller\Plugin;

use Common\Stdlib\EasyMeta;
use Common\Stdlib\PsrMessage;
use Generate\Api\Representation\GeneratedResourceRepresentation;
use Laminas\Authentication\AuthenticationServiceInterface;
use Laminas\I18n\Translator\TranslatorInterface;
use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Manager as ApiManager;
use Omeka\Settings\Settings;
use OpenAI;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Mvc\Controller\Plugin\Messenger;

class GenerateViaChatGpt extends AbstractPlugin
{
    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Laminas\Authentication\AuthenticationServiceInterface
     */
    protected $authentication;

    /**
     * @var \Common\Stdlib\EasyMeta
     */
    protected $easyMeta;

    /**
     * @var \Laminas\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Omeka\Mvc\Controller\Plugin\Messenger
     */
    protected $messenger;

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
        AuthenticationServiceInterface $authentication,
        EasyMeta $easyMeta,
        LoggerInterface $logger,
        Messenger $messenger,
        Settings $settings,
        TranslatorInterface $translator,
        string $apiKey
    ) {
        $this->api = $api;
        $this->authentication = $authentication;
        $this->easyMeta = $easyMeta;
        $this->logger = $logger;
        $this->messenger = $messenger;
        $this->settings = $settings;
        $this->translator = $translator;
        $this->apiKey = $apiKey;
    }

    /**
     * Generate resource metadata via ChatGPT.
     *
     * @see https://packagist.org/packages/chatgptcom/chatgpt-php
     *
     * @var array $options
     * - prompt (string): specific prompt
     */
    public function __invoke(ItemRepresentation|MediaRepresentation $resource, array $options = []): ?GeneratedResourceRepresentation
    {
        // Generating may be expensive, so there is a specific check for roles.
        $generateRoles = $this->settings->get('generate_roles') ?: [];
        $user = $this->authentication->getIdentity();
        if (!$user || !in_array($user->getRole(), $generateRoles)) {
            return null;
        }

        if (!$this->apiKey) {
            $this->logger->err('ChatGPT api key is undefined.'); // @translate
            return null;
        }

        // TODO Check if the files and urls are accessible from internet.

        if (empty($options['prompt'])) {
            $prompt = trim((string) $this->settings->get('generate_chatgpt_prompt'));
            if (!$prompt) {
                $configModule = include dirname(__DIR__, 4) . '/config/module.config.php';
                $prompt = $configModule['generate']['settings']['generate_chatgpt_prompt'];
                $this->messenger->addWarning(new PsrMessage(
                    'The prompt is not defined, so the record cannot be generated.' // @translate
                ));
                $this->logger->err('[Generate] Prompt is not defined.'); // @translate
                return null;
            }
        }

        $isMedia = false;
        if ($resource instanceof ItemRepresentation) {
            $medias = $resource->media();
            if (!count($medias)) {
                $this->messenger->addWarning(new PsrMessage(
                    'The item has no file, so the record cannot be generated.' // @translate
                ));
                $this->logger->warn(
                    '[Generate] The item #{item_id} has no file.', // @translate
                    ['item_id' => $resource->id()]
                );
                return null;
            }
        } elseif ($resource instanceof MediaRepresentation) {
            $isMedia = true;
            $medias = [$resource];
            return null;
        }

        // Get all media files.
        $urls = [];
        foreach ($medias as $media) {
            if ($media->renderer() === 'file'
                && $media->hasOriginal()
                && strtok((string) $media->mediaType(), '/') === 'image'
            ) {
                $urls[$media->id()] = $this->urlOrBase64($media);
            }
        }

        if (!count($urls)) {
            if ($isMedia) {
                $this->messenger->addWarning(new PsrMessage(
                    'The resource is not a file, not an image or the original file is missing, so the record cannot be generated.' // @translate
                ));
                $this->logger->warn(
                    '[Generate] The media #{media_id} is not a file, not an image or the original file is missing.', // @translate
                    ['media_id' => $resource->id()]
                );
            } else {
                $this->messenger->addWarning(new PsrMessage(
                    'The item has no files, or no image or no original file, so the record cannot be generated.' // @translate
                ));
                $this->logger->warn(
                    '[Generate] The item #{item_id} has no files, or no image or no original file.', // @translate
                    ['item_id' => $resource->id()]
                );
            }
            return null;
        }

        // Currently, ChatGPT and the library OpenAI does not support sending
        // prompt and image separately.
        // So append urls to prompt.
        $prompt = $this->preparePrompt($resource, $options['prompt'], $urls);
        if (!$prompt) {
            return null;
        }

        // Send the request to ChatGPT.
        $client = OpenAI::client($this->apiKey);

        try {
            /** @var \OpenAI\Responses\Chat\CreateResponse $response */
            $response = $client->chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);
        } catch (\Exception $e) {
            $this->messenger->addError(new PsrMessage(
                'An exception occurred: {msg}', // @translate
                ['msg' => $e->getMessage()]
            ));
            $this->logger->err(
                '[Generate] Exception for resource #{resource_id}: {msg}', // @translate
                ['msg' => $e->getMessage()]
            );
            return null;
        }

        /** @var \OpenAI\Responses\Chat\CreateResponseChoice $choice */
        $choice = $response->choices[0];
        $content = $choice->message->content;

        // Fill the generated resource.
        $resourceTemplate = $resource->resourceTemplate();
        $proposal = [
            'template' =>  $resourceTemplate ? $resourceTemplate->id() : null,
        ];

        // For now, just fill one property, since the response isn't structured.
        $proposal['curation:data'] = [
            [
                'proposed' => [
                    '@value' => $content,
                ],
            ],
        ];

        // Validate the generated resource.
        /** @see \Contribute\Controller\Site\ContributionController::submitAction() */

        // Store the generated resource.
        $data = [
            'o:resource' => ['o:id' => $resource->id()],
            'o:owner' => ['o:id' => $user->getId()],
            'o:reviewed' => false,
            'o:proposal' => $proposal,
        ];

        try {
            $generatedResource = $this->api->create('generated_resources', $data)->getContent();
        } catch (\Exception $e) {
            $this->messenger->addError(new PsrMessage(
                'An exception occurred when creating resource: {msg}', // @translate
                ['msg' => $e->getMessage()]
            ));
            $this->logger->err(
                '[Generate] Exception when creating generated resource for resource #{resource_id}: {msg}', // @translate
                ['msg' => $e->getMessage()]
            );
            return null;
        }

        return $generatedResource;
    }

    protected function preparePrompt(ItemRepresentation|MediaRepresentation $resource, string $prompt, array $urls): ?string
    {
        $replace = [];

        if (!str_contains($prompt, '{url}') && !str_contains($prompt, '{urls}')) {
            $prompt .= "\n{urls}";
        }

        if (mb_strpos($prompt, '{url}') !== false) {
            $replace['{url}'] = reset($urls);
        }

        if (mb_strpos($prompt, '{urls}') !== false) {
            $replace['{urls}'] = implode(', ', $urls);
        }

        if (mb_strpos($prompt, '{properties}') !== false) {
            /** @var \Omeka\Api\Representation\ResourceTemplateRepresentation $template */
            $template = $resource->resourceTemplate();
            if (!$template) {
                $this->messenger->addWarning(new PsrMessage(
                    'The prompt contains the placeholder "{properties}", but there is no template to replace it.' // @translate
                ));
                return null;
            }
            $list = [];
            foreach ($template->resourceTemplateProperties() as $rtp) {
                $list[] = $rtp->property()->term();
            }
            $replace['{properties}'] = implode(', ', $list);
        }

        if (mb_strpos($prompt, '{properties_names}') !== false) {
            /** @var \Omeka\Api\Representation\ResourceTemplateRepresentation $template */
            $template = $resource->resourceTemplate();
            if (!$template) {
                $this->messenger->addWarning(new PsrMessage(
                    'The prompt contains the placeholder "{properties_names}", but there is no template to replace it.' // @translate
                ));
                return null;
            }
            $list = [];
            foreach ($template->resourceTemplateProperties() as $rtp) {
                $property = $rtp->property();
                $list[] = $property->vocabulary()->label() . ' : ' . $property->label();
            }
            $replace['{properties_names}'] = implode(', ', $list);
        }

        return $replace
            ? strtr($prompt, $replace)
            : $prompt;
    }

    protected function urlOrBase64(MediaRepresentation $media): string
    {
        $url = $media->originalUrl();
        if (!$this->isUrlLocal($url)) {
            return $url;
        }
        $content = (string) @file_get_contents($url);
        return sprintf('data:%1$s;base64,%2$s', $media->mediaType(), base64_encode($content));
    }

    protected function isUrlLocal(string $url): bool
    {
        $parsedUrl = parse_url($url);

        // Manage local files.
        if (!isset($parsedUrl['host'])) {
            return true;
        }

        $host = $parsedUrl['host'];
        $ip = gethostbyname($host);

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return true;
        }

        return $ip === '::1'
            || $ip === '127.0.0.1'
            || preg_match('/^10\./', $ip)
            || preg_match('/^192\.168\./', $ip)
            || preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ip);
    }
}
