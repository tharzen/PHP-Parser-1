<?php declare(strict_types=1);

namespace PhpParser\Node\Expr;

use PhpParser\Node\Expr;

require_once "C:/Users/Alec Blagg/reversible-php/bam.php";
use bam;

class ArrayDimFetch extends Expr
{
    /** @var Expr Variable */
    public $var;
    /** @var null|Expr Array index / dim */
    public $dim;

    /**
     * Constructs an array index fetch node.
     *
     * @param Expr      $var        Variable
     * @param null|Expr $dim        Array index / dim
     * @param array     $attributes Additional attributes
     */
    public function __construct(Expr $var, Expr $dim = null, array $attributes = []) {
        $this->attributes = bam\Create($attributes);
        $this->var = bam\Create($var);
        $this->dim = bam\Create($dim);
    }

    public function getSubNodeNames() : array {
        return ['var', 'dim'];
    }
    
    public function getType() : string {
        return 'Expr_ArrayDimFetch';
    }
}
