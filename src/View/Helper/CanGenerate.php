<?php declare(strict_types=1);

namespace Generate\View\Helper;

use Laminas\View\Helper\AbstractHelper;

class CanGenerate extends AbstractHelper
{
    /**
     * Check if the current user can generate a new resource.
     */
    public function __invoke(): bool
    {
        $view = $this->getView();
        $setting = $view->plugin('setting');
        $user = $view->identity();
        return $user
            && in_array($user->getRole(), $setting('generate_roles', []) ?? []);
    }
}
