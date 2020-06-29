<?php


namespace SilverStripe\GraphQL\Schema;


interface ContextProvider
{
    /**
     * @param string $key
     * @param $val
     * @return ContextProvider
     */
    public function addContext(string $key, $val): self;

    /**
     * @return array
     */
    public function getContext(): array;

}
