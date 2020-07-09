<?php declare(strict_types=1);

namespace PhpParser\Node\Stmt;

use PhpParser\Node;

require_once "C:/Users/Alec Blagg/reversible-php/bam.php";
use bam;

/**
 * Represents statements of type "expr;"
 */
class Expression extends Node\Stmt
{
    /** @var Node\Expr Expression */
    public $expr;

    /**
     * Constructs an expression statement.
     *
     * @param Node\Expr $expr       Expression
     * @param array     $attributes Additional attributes
     */
    public function __construct(Node\Expr $expr, array $attributes = []) {
        $this->attributes = bam\Create($attributes);
        $this->expr = bam\Create($expr);
    }

    public function getSubNodeNames() : array {
        return ['expr'];
    }
    
    public function getType() : string {
        return 'Stmt_Expression';
    }
}
