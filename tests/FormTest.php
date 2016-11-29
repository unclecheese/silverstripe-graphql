<?php

namespace SilverStripe\Tests\GraphQL;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\GraphQL\Manager;
use SilverStripe\GraphQL\Controller;
use SilverStripe\GraphQL\Tests\Fake\FormFake;
use SilverStripe\Core\Config\Config;
use GraphQL\Schema;
use GraphQL\Type\Definition\ObjectType;
use ReflectionClass;
use Exception;

class FormTest extends SapphireTest
{
    public function testFormSubmission()
    {
        $controller = new Controller();
        $manager = new Manager();

        $controller->setManager($manager);
        $response = $controller->index(new HTTPRequest('POST', '', '', [
            'operationName' => 'SubmitForm',
            'query' => '
                mutation SubmitForm($data: [fieldSubmission]!, $className: String!, $action: String!) {
                    submitForm(data: $data, className: $className, action: $action) {
                        messages
                        updateQueries
                        updatedRecords
                        addedRecords
                        deletedRecords
                        scheme
                        errors
                        messages
                    }
                }',
            'variables' => [
                'action' => 'submit',
                'className' => 'SilverStripe\\GraphQL\\Tests\\Fake\\FormFake',
                'data' => [
                    'name' => 'Name',
                    'email' => 'demo@user.com'
                ]
            ]
        ]));

        $this->assertFalse($response->isError());
    }
}
