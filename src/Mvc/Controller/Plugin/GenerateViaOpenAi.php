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

class GenerateViaOpenAi extends AbstractPlugin
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

    /**
     * @var string|null
     */
    protected $organization;

    /**
     * @var string|null
     */
    protected $project;

    public function __construct(
        ApiManager $api,
        AuthenticationServiceInterface $authentication,
        EasyMeta $easyMeta,
        LoggerInterface $logger,
        Messenger $messenger,
        Settings $settings,
        TranslatorInterface $translator,
        string $apiKey,
        ?string $organization,
        ?string $project
    ) {
        $this->api = $api;
        $this->authentication = $authentication;
        $this->easyMeta = $easyMeta;
        $this->logger = $logger;
        $this->messenger = $messenger;
        $this->settings = $settings;
        $this->translator = $translator;
        $this->apiKey = $apiKey;
        $this->organization = $organization;
        $this->project = $project;
    }

    /**
     * Generate resource metadata via OpenAI.
     *
     * @see https://packagist.org/packages/chatgptcom/chatgpt-php
     *
     * @var array $options
     * - model (string): the model to use.
     * - max_tokens (int): the max tokens to use for a request (0 for no limit).
     * - derivative (string): the derivative image type or original to process.
     * - prompt_system (string|false): specific prompt for the system (session).
     *   The configured prompt in settings is used by default, unless false is
     *   passed.
     * - prompt_user (string): specific prompt.
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
            $this->logger->err('OpenAI api key is undefined.'); // @translate
            return null;
        }

        $models = $this->settings->get('generate_models') ?: [];
        if (!$models) {
            $this->logger->warn(
                '[Generate] The list of models is empty.' // @translate
            );
            return null;
        }

        $model = empty($options['model'])
            ? trim((string) $this->settings->get('generate_model'))
            : trim((string) $options['model']);
        if (!isset($models[$model])) {
            $this->logger->warn(
                '[Generate] The model "{model}" is not in the list of allowed models.', // @translate
                ['model' => $model]
            );
        }

        $maxTokens = empty($options['max_tokens'])
            ? (int) $this->settings->get('generate_max_tokens')
            : (int) $options['max_tokens'];

        $derivative = empty($options['derivative'])
            ? $this->settings->get('generate_derivative')
            : 'medium';

        // The prompt for session or for user may be skipped, not the two.

        if (empty($options['prompt_system']) && $options['prompt_system'] !== false) {
            $options['prompt_system'] = trim((string) $this->settings->get('generate_prompt_system'));
        }

        if (empty($options['prompt_user']) && $options['prompt_user'] !== false) {
            $options['prompt_user'] = trim((string) $this->settings->get('generate_prompt_user'));
        }

        if (empty($options['prompt_user']) && empty($options['prompt_user'])) {
            $this->messenger->addWarning(new PsrMessage(
                'The prompt is not defined, so the record cannot be generated.' // @translate
            ));
            $this->logger->warn('[Generate] Prompt is not defined.'); // @translate
            return null;
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
        $useOriginal = $derivative === 'original';
        foreach ($medias as $media) {
            if ($media->renderer() === 'file'
                && (($useOriginal && $media->hasOriginal()) || (!$useOriginal && $media->hasThumbnails()))
                && strtok((string) $media->mediaType(), '/') === 'image'
            ) {
                $urls[$media->id()] = $this->urlOrBase64($media, $derivative);
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

        $messages = [];

        $promptSystem = $this->preparePrompt($resource, $options['prompt_system'], $urls);
        if ($promptSystem) {
            $messages[] = [
                'role' => 'system',
                'content' => $promptSystem,
            ];
        }

        $messageUser = [
            'role' => 'user',
            'content' => [],
        ];

        $promptUser = $this->preparePrompt($resource, $options['prompt_user'], $urls);
        if ($promptUser) {
            $messageUser['content'][] = [
                'type' => 'text',
                'text' => $promptUser,
            ];
        }

        foreach ($urls as $url) {
            $messageUser['content'][] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $url,
                ],
            ];
        }

        $messages[] = $messageUser;

        // Send the request to OpenAI.
        $client = OpenAI::client($this->apiKey, $this->organization, $this->project);

        try {
            /** @var \OpenAI\Responses\Chat\CreateResponse $response */
            $response = $client->chat()->create([
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => $maxTokens,
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

    protected function preparePrompt(ItemRepresentation|MediaRepresentation $resource, ?string $prompt, array $urls): ?string
    {
        $replace = [];

        $prompt = (string) $prompt;

        if (str_contains($prompt, '{url}')) {
            $replace['{url}'] = reset($urls);
        }

        if (str_contains($prompt, '{urls}')) {
            $replace['{urls}'] = implode(', ', $urls);
        }

        $missingTemplate = function (string $placeholder): null {
            $this->messenger->addWarning(new PsrMessage(
                'The prompt contains the placeholder "{placeholder}", but there is no template to replace it.', // @translate
                ['placeholder' => $placeholder]
            ));
            $this->logger->warn(
                '[Generate] The prompt contains the placeholder "{placeholder}", but there is no template to replace it.', // @translate
                ['placeholder' => $placeholder]
            );
            return null;
        };

        /** @var \Omeka\Api\Representation\ResourceTemplateRepresentation $template */
        $template = $resource->resourceTemplate();

        if (str_contains($prompt, '{properties}')) {
            if ($template) {
                $list = [];
                foreach ($template->resourceTemplateProperties() as $rtp) {
                    $list[] = $rtp->property()->term();
                }
                $replace['{properties}'] = implode(', ', $list);
            } else {
                $missingTemplate('{properties}');
                $replace['{properties}'] = '';
            }
        }

        if (str_contains($prompt, '{properties_names}')) {
            if ($template) {
                $list = [];
                foreach ($template->resourceTemplateProperties() as $rtp) {
                    $property = $rtp->property();
                    $list[] = $property->vocabulary()->label() . ' : ' . $property->label();
                }
                $replace['{properties_names}'] = implode(', ', $list);
            } else {
                $missingTemplate('{properties_names}');
                $replace['{properties_names}'] = '';
            }
        }

        if (str_contains($prompt, '{properties_sample}')) {
            if ($template) {
                $list = [];
                foreach ($template->resourceTemplateProperties() as $rtp) {
                    $property = $rtp->property();
                    $list[$property->term()] = sprintf('Example %s', $property->label()); // @translate
                }
                $replace['{properties_sample}'] =  json_encode($list, 320);
            } else {
                $missingTemplate('{properties_sample}');
                $replace['{properties_sample}'] = '';
            }
        }

        if (str_contains($prompt, '{properties_sample_json}')) {
            if ($template) {
                $list = [];
                foreach ($template->resourceTemplateProperties() as $rtp) {
                    $property = $rtp->property();
                    $list[$property->term()] = sprintf('Example %s', $property->label()); // @translate
                }
                $replace['{properties_sample_json}'] = '```json' . "\n"
                    . json_encode($list, 320)
                    . '```';
            } else {
                $missingTemplate('{properties_sample_json}');
                $replace['{properties_sample_json}'] = '';
            }
        }

        return $replace
            ? strtr($prompt, $replace)
            : $prompt;
    }

    protected function urlOrBase64(MediaRepresentation $media, string $derivative): string
    {
        $url = $derivative === 'original'
            ? $media->originalUrl()
            : $media->thumbnailUrl($derivative);
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
