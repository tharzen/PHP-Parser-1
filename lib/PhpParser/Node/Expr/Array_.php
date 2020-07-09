<?php declare(strict_types=1);

namespace PhpParser\Node\Expr;

use PhpParser\Node\Expr;

require_once "C:/Users/Alec Blagg/reversible-php/bam.php";
use bam;

class Array_ extends Expr
{
    // For use in "kind" attribute
    const KIND_LONG = 1;  // array() syntax
    const KIND_SHORT = 2; // [] syntax

    /** @var ArrayItem[] Items */
    public $items;

    /**
     * Constructs an array node.
     *
     * @param ArrayItem[] $items      Items of the array
     * @param array       $attributes Additional attributes
     */
    public function __construct(array $items = [], array $attributes = []) {
        $this->attributes = bam\Create($attributes);
        $this->items = bam\Create($items);
    }

    public function getSubNodeNames() : array {
        return ['items'];
    }
    
    public function getType() : string {
        return 'Expr_Array';
    }
}
