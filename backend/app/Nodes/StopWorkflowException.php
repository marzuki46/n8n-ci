<?php

namespace App\Nodes;

/**
 * Dilempar node Stop untuk menghentikan eksekusi workflow secara normal
 * (bukan error). Engine menangkapnya lalu mengakhiri eksekusi dengan status 'stopped'.
 */
class StopWorkflowException extends \Exception
{
}
