<?php declare(strict_types=1);

namespace Generate\Mvc\Controller\Plugin;

use ArrayObject;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Representation\ResourceTemplateRepresentation;

class GenerativeData extends AbstractPlugin
{
    /**
     * @var \ArrayObject
     */
    protected $data;

    /**
     * Get generative data (editable, fillable, etc.) of a resource template.
     *
     * Unlike module Contribute, all resource templates are allowed.
     * Unlike module Contribute, a resource template is required.
     * Unlike module Contribute, when not data type is set in the template, the
     * standard data types are allowed (literal, uri, resource, resource:item, resource:media, resource:itemset).
     * So in fact, only literal and uri, because the AI doesn't know resources.
     *
     * Furthermore, all properties are fillable by default, according to the
     * resource template settings.
     *
     * The template can contain a sub-template for files. It is set in the main
     * resource template too (one level recursivity).
     *
     * The input for a file is specific and not managed here.
     *
     * @todo Remove code that set fields or use default data types without resource template.
     *
     * @todo Add a template setting to allow property not in template.
     * @todo Add a template property setting to follow/skip property in the list of properties (like contributive), but by default all are possible.
     *
     * @param \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation|\Omeka\Api\Representation\ResourceTemplateRepresentation|string|int|null $template
     */
    public function __invoke($resourceTemplate = null, ?bool $isSubTemplate = false): self
    {
        $isSubTemplate = (bool) $isSubTemplate;
        $this->data = new ArrayObject([
            'is_generative' => false,
            'template' => null,
            'required' => false,
            'min_values' => 0,
            'max_values' => 0,
            'editable_mode' => 'whitelist',
            'editable' => [],
            'fillable_mode' => 'whitelist',
            'fillable' => [],
            'datatype' => [],
            'datatypes_default' => [],
            'templates_media' => [],
            // Keep null when not checked, then array.
            'generative_medias' => null,
            // Following keys are kept for compatibility with old themes.
            'is_sub_template' => $isSubTemplate,
            'template_media' => null,
            'generative_media' => false,
        ]);

        $controller = $this->getController();
        $this->data['datatypes_default'] = ['literal', 'resource', 'uri'];

        // TODO Manage valuesuggest and custom vocab differently, because it is not a single data type.
        // TODO Remove default data types (or limit it to literal) (currently hard coded like omeka, so useless).
        // TODO Check if these check are useless, since a resource template is required.
        if (($has = array_search('valuesuggest', $this->data['datatypes_default'])) !== false) {
            unset($this->data['datatypes_default'][$has]);
        }
        if (($has = array_search('customvocab', $this->data['datatypes_default'])) !== false) {
            unset($this->data['datatypes_default'][$has]);
        }

        /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $resourceTemplate */
        $resourceTemplate = $this->resourceTemplate($resourceTemplate);

        if (!$resourceTemplate) {
            $controller->logger()->err('A resource template must be set to allow to generate metadata.'); // @translate
            return $this;
        }

        $this->data['template'] = $resourceTemplate;

        if (!method_exists($resourceTemplate, 'data')) {
            $controller->logger()->err('The module Advanced Resource Template is not available.'); // @translate
            return $this;
        }

        /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyRepresentation $resourceTemplateProperty */
        foreach ($resourceTemplate->resourceTemplateProperties() as $resourceTemplateProperty) {
            $property = $resourceTemplateProperty->property();
            $propertyId = $property->id();
            $term = $property->term();
            $this->data['datatype'][$term] = $resourceTemplateProperty->dataTypes() ?: $this->data['datatypes_default'];
            $this->data['required'] = $resourceTemplateProperty->isRequired();
            $rtpData = $resourceTemplateProperty->mainData();
            if (!$rtpData) {
                continue;
            }
            // TODO Manage repeatable property.
            $this->data['min_values'] = (int) $rtpData->dataValue('min_values');
            $this->data['max_values'] = (int) $rtpData->dataValue('max_values');
            /*
            if ($rtpData->dataValue('editable')) {
                $this->data['editable'][$term] = $propertyId;
            }
            if ($rtpData->dataValue('fillable')) {
                $this->data['fillable'][$term] = $propertyId;
            }
            */
            $this->data['editable'][$term] = $propertyId;
            $this->data['fillable'][$term] = $propertyId;
        }

        // When a sub-template is not available, there is no break to allow to
        // submit partially.
        if (!$isSubTemplate) {
            /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation[] $resourceTemplateMedias */
            $resourceTemplateMediaIds = $resourceTemplate->dataValue('generate_templates_media') ?: [];
            foreach ($resourceTemplateMediaIds as $resourceTemplateMediaId) {
                $resourceTemplateMedia = $this->resourceTemplate($resourceTemplateMediaId);
                if (!$resourceTemplateMedia) {
                    $controller->logger()->err(
                        'The resource template #{template_id} used for media in template {template} is not available.', // @translate
                        ['template_id' => $resourceTemplateMediaId, 'template' => $resourceTemplate->label()]
                    );
                } else {
                    $this->data['templates_media'][$resourceTemplateMedia->id()] = $resourceTemplateMedia;
                }
            }
        }

        // The resource template is checked above.
        $this->data['is_generative'] = count($this->data['datatypes_default'])
            || count($this->data['editable'])
            || count($this->data['fillable'])
            // TODO Remove editable mode / fillable mode since a template is required now.
            || in_array($this->data['editable_mode'], ['all', 'blacklist'])
            || in_array($this->data['fillable_mode'], ['all', 'blacklist']);

        return $this;
    }

    public function data(): ArrayObject
    {
        return $this->data;
    }

    public function isGenerative(): bool
    {
        return $this->data['is_generative'];
    }

    /**
     * @todo Always true: remove this method.
     */
    public function hasTemplate(): bool
    {
        return !empty($this->data['template']);
    }

    public function template(): ?ResourceTemplateRepresentation
    {
        return $this->data['template'];
    }

    public function isRequired(): bool
    {
        return $this->data['required'];
    }

    public function minValues(): int
    {
        return $this->data['min_values'];
    }

    public function maxValues(): int
    {
        return $this->data['max_values'];
    }

    public function editableMode(): string
    {
        return $this->data['editable_mode'];
    }

    public function editableProperties(): array
    {
        return $this->data['editable'];
    }

    public function fillableMode(): string
    {
        return $this->data['fillable_mode'];
    }

    public function fillableProperties(): array
    {
        return $this->data['fillable'];
    }

    public function dataTypeProperties(): array
    {
        return $this->data['datatype'];
    }

    public function defaultDataTypes(): array
    {
        return $this->data['datatypes_default'];
    }

    public function dataTypeTerm(?string $term): array
    {
        return empty($this->data['datatype'][$term])
            ? $this->data['datatypes_default']
            : $this->data['datatype'][$term];
    }

    public function isTermGenerative(?string $term): bool
    {
        return $this->isTermEditable($term)
            || $this->isTermFillable($term);
    }

    public function isTermEditable(?string $term): bool
    {
        if ($this->hasTemplate()) {
            return isset($this->data['editable'][$term])
                && !empty($this->data['datatype'][$term]);
        }
        return count($this->data['datatypes_default'])
            && (
                ($this->data['editable_mode'] === 'all')
                || ($this->data['editable_mode'] === 'whitelist' && isset($this->data['editable'][$term]))
                || ($this->data['editable_mode'] === 'blacklist' && !isset($this->data['editable'][$term]))
            );
    }

    public function isTermFillable(?string $term): bool
    {
        if ($this->hasTemplate()) {
            return isset($this->data['fillable'][$term])
                && !empty($this->data['datatype'][$term]);
        }
        return count($this->data['datatypes_default'])
            && (
                ($this->data['fillable_mode'] === 'all')
                || ($this->data['fillable_mode'] === 'whitelist' && isset($this->data['fillable'][$term]))
                || ($this->data['fillable_mode'] === 'blacklist' && !isset($this->data['fillable'][$term]))
            );
    }

    /**
     * Check if the data type is managed for the specified term.
     */
    public function isTermDataType(?string $term, ?string $dataType): bool
    {
        if ($this->hasTemplate()) {
            return !empty($this->data['datatype'][$term])
                && in_array($dataType, $this->data['datatype'][$term]);
        }
        return $this->isDefaultDataType($dataType);
    }

    public function isDefaultDataType(?string $dataType): bool
    {
        return in_array($dataType, $this->data['datatypes_default']);
    }

    public function isSubTemplate(): bool
    {
        return $this->data['is_sub_template'];
    }

    /**
     * Get the generative data for the media sub-templates.
     *
     * Like main template, the media templates should have at least one property.
     *
     * @return \Generate\Mvc\Controller\Plugin\GenerativeData[]
     */
    public function generativeMedias(): array
    {
        if ($this->data['generative_medias'] === []
            || !$this->isGenerative()
            || empty($this->data['templates_media'])
            || $this->isSubTemplate()
        ) {
            return [];
        }

        if ($this->data['generative_medias']) {
            return $this->data['generative_medias'];
        }

        $this->data['generative_medias'] = [];
        foreach ($this->data['templates_media'] as $templateMediaId => $templateMedia) {
            // Clone() allows to get to generative data with a different config.
            /** @var \Generate\Mvc\Controller\Plugin\GenerativeData $generativeMedia */
            $generativeMedia = clone $this->getController()->plugin('generativeData');
            $generativeMedia = $generativeMedia($templateMedia, true);
            if ($generativeMedia->isGenerative()) {
                $this->data['generative_medias'][$templateMediaId] = $generativeMedia;
            }
        }

        // Temporary keep one template for compatibility with old themes.
        $this->data['generative_media'] = count($this->data['generative_medias'])
            ? reset($this->data['generative_medias'])
            : null;

        return $this->data['generative_medias'];
    }

    /**
     * Get the generative data for the media sub-templates.
     *
     * Like main template, the media template should have at least one property.
     */
    public function generativeMedia(?int $mediaTemplateId = null): ?\Generate\Mvc\Controller\Plugin\GenerativeData
    {
        $generativeMedias = $this->generativeMedias();
        if ($mediaTemplateId) {
            return $generativeMedias[$mediaTemplateId] ?? null;
        }
        return count($generativeMedias) ? reset($generativeMedias) : null;
    }

    /**
     * Get the resource template.
     *
     * @var \Omeka\Api\Representation\ResourceTemplateRepresentation|int|string|null $resourceTemplate
     *
     * @return \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation|ResourceTemplateRepresentation|
     */
    protected function resourceTemplate($template): ?ResourceTemplateRepresentation
    {
        if (empty($template) || is_object($template)) {
            return $template ?: null;
        }

        try {
            return $this->getController()->api()->read('resource_templates', is_numeric($template) ? ['id' => $template] : ['label' => $template])->getContent();
        } catch (\Omeka\Api\Exception\NotFoundException $e) {
            if (is_numeric($template)) {
                $this->getController()->logger()->warn(
                    'The template #{template_id} does not exist and cannot be used for generation.', // @translate
                    ['template_id' => $template]
                );
            } else {
                $this->getController()->logger()->warn(
                    'The template "{template}" does not exist and cannot be used for generation.', // @translate
                    ['template' => $template]
                );
            }
            return null;
        }
    }
}
