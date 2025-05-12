<?php declare(strict_types=1);

namespace Generate\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element as OmekaElement;

class SettingsFieldset extends Fieldset
{
    protected $label = 'Generate'; // @translate

    protected $elementGroups = [
        'generation' => 'Generation', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'generate')
            ->setOption('element_groups', $this->elementGroups)
            ->add([
                'name' => 'generate_mode',
                'type' => Element\Radio::class,
                'options' => [
                    'element_group' => 'generation',
                    'label' => 'Generation mode', // @translate
                    'value_options' => [
                        'user_token' => 'Authenticated users with a token', // @translate
                        'auth_cas' => 'Authenticated users from cas', // @translate
                        'auth_ldap' => 'Authenticated users from ldap', // @translate
                        'auth_sso' => 'Authenticated users from sso', // @translate
                        'email_regex' => 'Authenticated users with an email matching regex below', // @translate
                        'user' => 'Authenticated users', // @translate
                        'role' => 'Roles', // @translate
                        'token' => 'With token', // @translate
                        'open' => 'Open to any visitor', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'generate_mode',
                    'value' => 'user_token',
                ],
            ])

            ->add([
                // This element is a select built with a factory, not a class.
                // Anyway, it cannot be used simply, because it requires a value.
                // 'type' => 'Omeka\Form\Element\RoleSelect',
                'type' => CommonElement\OptionalRoleSelect::class,
                'name' => 'generate_roles',
                'options' => [
                    'element_group' => 'generation',
                    'label' => 'Roles allowed to generate (option "Roles" above)', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'generate_roles',
                    'multiple' => true,
                    'required' => false,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select roles…', // @translate
                ],
            ])

            ->add([
                'name' => 'generate_email_regex',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'generation',
                    'label' => 'Regex on email of users allowed to generate (option above)', // @translate
                ],
                'attributes' => [
                    'id' => 'generate_email_regex',
                ],
            ])

            ->add([
                'name' => 'generate_templates',
                'type' => CommonElement\OptionalResourceTemplateSelect::class,
                'options' => [
                    'element_group' => 'generation',
                    'label' => 'Resource templates allowed for generation', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'generate_templates',
                    'multiple' => true,
                    'required' => false,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select resources templates…', // @translate
                ],
            ])

            ->add([
                'name' => 'generate_templates_media',
                'type' => CommonElement\OptionalResourceTemplateSelect::class,
                'options' => [
                    'element_group' => 'generation',
                    'label' => 'Resource templates allowed for media (linked generation)', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'generate_templates_media',
                    'multiple' => true,
                    'required' => false,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select resources templates…', // @translate
                ],
            ])

            ->add([
                'name' => 'generate_token_duration',
                'type' => Element\Number::class,
                'options' => [
                    'element_group' => 'generation',
                    'label' => 'Days for token to expire', // @translate
                    'info' => 'Allow to set the default expiration date of a token. Let empty to remove expiration.', // @translate
                ],
                'attributes' => [
                    'id' => 'generate_token_duration',
                    'min' => '0',
                    'step' => '1',
                    'data-placeholder' => '90', // @translate
                ],
            ])

            ->add([
                'name' => 'generate_allow_update',
                'type' => Element\Radio::class,
                'options' => [
                    'element_group' => 'generation',
                    'label' => 'Allow to edit a generation', // @transale
                    'value_options' => [
                        'no' => 'No (directly submitted)', // @translate
                        'submission' => 'Until submission', // @translate
                        'validation' => 'Until validation', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'generate_allow_update',
                    'value' => 'submit',
                ],
            ])

            ->add([
                'name' => 'generate_notify_recipients',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'element_group' => 'generation',
                    'label' => 'Emails to notify generations', // @translate
                    'info' => 'A query can be appended to limit notifications to specific generations.', // @translate
                ],
                'attributes' => [
                    'id' => 'generate_notify_recipients',
                    'required' => false,
                    'placeholder' => 'contact@example.org
info@example2.org resource_template_id[]=2&property[0][property]=dcterms:provenance&property[0][type]=eq&property[0][text]=ut2j
',
                ],
            ])

            ->add([
                'name' => 'generate_author_emails',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'element_group' => 'generation',
                    'label' => 'Emails of the author', // @translate
                    'empty_option' => '',
                    'prepend_value_options' => [
                        'owner' => 'Generator', // @translate
                    ],
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'generate_author_emails',
                    'multiple' => true,
                    'required' => false,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a property…', // @translate
                ],
            ])

            ->add([
                'name' => 'generate_author_confirmations',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'generation',
                    'label' => 'Confirmations to author', // @translate
                    'value_options' => [
                        // 'prepare' => 'On prepare', // @translate
                        // 'update' => 'On update', // @translate
                        'submit' => 'On submit', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'generate_author_confirmations',
                    'required' => false,
                ],
            ])

            ->add([
                'name' => 'generate_message_add',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'generation',
                    'label' => 'Message displayed when a generation is added', // @translate
                ],
                'attributes' => [
                    'id' => 'generate_message_add',
                ],
            ])

            ->add([
                'name' => 'generate_message_edit',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'generation',
                    'label' => 'Message displayed when a generation is edited', // @translate
                ],
                'attributes' => [
                    'id' => 'generate_message_edit',
                ],
            ])

            ->add([
                'name' => 'generate_author_confirmation_subject',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'generation',
                    'label' => 'Subject of the confirmation email to the author', // @translate
                    'info' => 'May be overridden by a specific subject set in the resource template', // @translate
                ],
                'attributes' => [
                    'id' => 'generate_author_confirmation_subject',
                ],
            ])
            ->add([
                'name' => 'generate_author_confirmation_body',
                'type' => Element\Textarea::class,
                'options' => [
                    'element_group' => 'generation',
                    'label' => 'Confirmation message to the author', // @translate
                    'info' => 'Placeholders: wrap properties with "{}", for example "{dcterms:title}".', // @translate
                ],
                'attributes' => [
                    'id' => 'generate_author_confirmation_body',
                    'rows' => 5,
                ],
            ])

            ->add([
                'name' => 'generate_reviewer_confirmation_subject',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'generation',
                    'label' => 'Subject of the confirmation email to the reviewers', // @translate
                    'info' => 'May be overridden by a specific subject set in the resource template', // @translate
                ],
                'attributes' => [
                    'id' => 'generate_reviewer_confirmation_subject',
                ],
            ])
            ->add([
                'name' => 'generate_reviewer_confirmation_body',
                'type' => Element\Textarea::class,
                'options' => [
                    'element_group' => 'generation',
                    'label' => 'Confirmation message to the reviewers', // @translate
                    'info' => 'Placeholders: wrap properties with "{}", for example "{dcterms:title}".', // @translate
                ],
                'attributes' => [
                    'id' => 'generate_author_confirmation_body',
                    'rows' => 5,
                ],
            ])
        ;
    }
}
