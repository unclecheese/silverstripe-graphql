<?php

namespace SilverStripe\GraphQL\Forms;

use GraphQL\Type\Definition\Type;
use SilverStripe\GraphQL\TypeCreator;

/**
 * Represents a single field submission with a name and value within a form.
 *
 * Form values are not strictly typed. They will be resolved as strings.
 *
 * @todo strictly type input?
 * @todo how would Form array submit?
 */
class FieldSubmissionTypeCreator extends TypeCreator
{
    /**
     * Use on input of the form. GraphQL will not return a fieldSubmission but
     * it will use it for the input.
     *
     * @var boolean
     */
    protected $inputObject = true;

    /**
     * @return array
     */
    public function attributes()
    {
        return [
            'name' => 'fieldSubmission',
        ];
    }

    /**
     * @return array
     */
    public function fields()
    {
        return [
            'name' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Form field name'
            ],
            'value' => [
                'type' => Type::string(),
                'description' => 'Form field value'
            ],
        ];
    }
}
