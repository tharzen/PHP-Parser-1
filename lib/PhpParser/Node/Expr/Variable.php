<?php declare(strict_types=1);

namespace PhpParser\Node\Expr;

use PhpParser\Node\Expr;

require_once "C:/Users/Alec Blagg/reversible-php/bam.php";
use bam;

class Variable extends Expr
{
    /** @var string|Expr Name */
    public $name;

    /**
     * Constructs a variable node.
     *
     * @param string|Expr $name       Name
     * @param array                      $attributes Additional attributes
     */
    public function __construct($name, array $attributes = []) {
        $this->attributes = bam\Create($attributes);
        $this->name = bam\ReuseArray($attributes["startFilePos"] + 1, bam\Create(""),
            ($attributes["endFilePos"] - $attributes["startFilePos"]), bam\Reuse());
    }

    public function getSubNodeNames() : array {
        return ['name'];
    }
    
    public function getType() : string {
        return 'Expr_Variable';
    }
}
