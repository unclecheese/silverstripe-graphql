<?php

namespace SilverStripe\GraphQL\Forms;

use SilverStripe\GraphQL\MutationCreator;
use SilverStripe\Core\Injector\Injector;
use GraphQL\Type\Definition\Type;

// temp work around
use SilverStripe\AssetAdmin\Controller\AssetAdmin;

/**
 * This GraphQL mutation handles submissions from a form with a given URL and
 * delegates the handling of the form to the specific {@link Form}.
 *
 * A GraphQL submission outline looks like the following:
 *
 * <code>
 * mutation ($data:[fieldSubmission]!, $className:String!, $action:String!) {
 *   submitForm(data:$data, className:$className, action: $action) {
 *     errors
 *   }
 * }
 *
 * {
 *  "data": [{name: "Field", value}],
 *  "className": "SilverStripe\\Form",
 *  "action": "action_submit"
 * }
 * </code>
 */
class FormMutationCreator extends MutationCreator {

    /**
     * @return array
     */
    public function attributes()
    {
        return [
            'name' => 'submitForm'
        ];
    }

    /**
     * The result of a standard mutation via a form is a GraphQL `formResult`.
     *
     * See {@link FormResultTypeCreator}
     *
     * @return callable
     */
    public function type()
    {
        return function() {
            return $this->manager->getType('formResult');
        };
    }

    /**
     * Form submission.
     *
     * The fully qualified className of the {@link FormFactory} should be passed
     * along with the mutation to recreate the form object. Any context required
     * for the form to recreate itself should be passed as `data` similar to a
     * standard POST request.
     *
     * @return array
     */
    public function args()
    {
        return [
            'data' => [
                'type' => Type::listOf($this->manager->getType('fieldSubmission')),
                'description' => 'List of fields submitted'
            ],
            'className' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Fully qualified class name for the given form instance'
            ],
            'action' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Name of the FormAction to trigger'
            ],
        ];
    }

    /**
     * Handle the form submission.
     *
     * Initiates a new instance of the {@link Form} record and hands it's off
     * to its' resolve function. Note that this process will bypass the
     * controller context that the form is operating on so the form has to be
     * responsible for permission checking. All context required for the form
     * should be passed either as POST data attached to the form or as part of
     * the scheme.
     *
     * Returns a GraphQL `FormResultTypeCreator` type containing any updated
     * record keys and the new {@link FormSchema} as the form may change
     * behavior with updated data (i.e when saving a page, new fields may be
     * added)
     *
     * @param Object $object
     * @param array $args
     * @param array $context
     * @param array $info
     *
     * @todo requires silverstripe-framework/issues/6334
     *
     * @return array
     */
    public function resolve($object, array $args, $context, $info)
    {
        // move the return to the specific form cases
        $form = AssetAdmin::create()->getFileEditForm(3);
        $schema = Injector::inst()->create('FormSchema');

        // requires silverstripe-framework/issues/6334
        return [
            'messages' => null,
            'schema' => $schema->getSchema($form),
            'state' => $schema->getState($form),
            'errors' => null,
            'updatedRecords' => [
                'File:3'
            ]
        ];
    }
}
