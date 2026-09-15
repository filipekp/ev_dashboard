<?php

declare(strict_types=1);
namespace App;

use RuntimeException;

/**
 * Třída Template.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class Template
{
    /** @var string */ private $root;
    public function __construct(string $root){$this->root=rtrim($root,'/\\');}
    /** @param array<string,mixed> $data */
    public function render(string $name,array $data=[]): void
    {
        $file=$this->root.'/'.$name.'.php'; if(!is_file($file))throw new RuntimeException('Šablona nebyla nalezena: '.$name);
        extract($data,EXTR_SKIP); require $file;
    }
}
