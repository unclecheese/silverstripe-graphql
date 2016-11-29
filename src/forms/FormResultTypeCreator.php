<?php

namespace SilverStripe\GraphQL\Forms;

use GraphQL\Type\Definition\Type;
use SilverStripe\GraphQL\TypeCreator;
use SilverStripe\GraphQL\JsonType;

/**
 * Encapsulates a GraphQL response to a {@link FormSubmissionTypeCreator}.
 *
 */
class FormResultTypeCreator extends TypeCreator
{
    /**
     * @return array
     */
    public function attributes()
    {
        return [
            'name' => 'formResult'
        ];
    }

    /**
     * @return array
     */
    public function fields()
    {
        $json = new JsonType();

        return [
            'updateQueries' => [
                'type' => Type::listOf(Type::string()),
                'description' => 'A list of named queries to re-evaluate'
            ],
            'updatedRecords' => [
                'type' => Type::listOf(Type::string()),
                'description' => 'A list of modified keys for Apollo to fetch.'
            ],
            'addedRecords' => [
                'type' => Type::listOf(Type::string()),
                'description' => 'A list of added record keys'
            ],
            'deletedRecords' => [
                'type' => Type::listOf(Type::string()),
                'description' => 'A list of deleted record keys'
            ],
            'schema' => [
                'type' => $json,
                'description' => 'Updated form schema'
            ],
            'state' => [
                'type' => $json,
                'description' => 'Updated form state'
            ],
            'errors' => [
                'type' => Type::listOf(Type::string()),
                'description' => 'Error messages'
            ],
            'messages' => [
                'type' => Type::listOf(Type::string()),
                'description' => 'Success message'
            ],
        ];
    }
}


