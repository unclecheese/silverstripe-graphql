<?php

namespace SilverStripe\GraphQL;

use SilverStripe\Core\Object;
use GraphQL\Type\Definition\ScalarType;
use SilverStripe\GraphQL\Manager;

/**
 * A custom {@link ScalarType} to represent arbitrary JSON based back to the
 * client.
 */

class JsonType extends ScalarType {

    /**
     * @var string $name
     */
    public $name = 'JsonType';


    public function __construct() {
        parent::__construct();
    }

    /**
     * @param array $value
     *
     * @return string
     */
    public function serialize($value) {
        return json_encode($value);
    }

    /**
     * @param array $value
     *
     * @return string
     */
    public function parseValue($value) {
        return $value;
    }

    /**
     * @param GraphQL\Language\AST\Value
     */
    public function parseLiteral($valueAST) {
        return $valueAST;
    }
}
