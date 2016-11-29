<?php

namespace SilverStripe\GraphQL\Tests;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

class FormFake extends Form implements TestOnly
{
    public function Fields()
    {
        return new FieldList(
            new TextField('Name'),
            new EmailField('Email')
        );
    }

    public function getAllActions()
    {
        return new FieldList(
            FormAction::create('submit', 'Submit')->submit(function() {
                // @todo, requires a core framework change
            })
        );
    }
}
