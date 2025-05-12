<?php declare(strict_types=1);

namespace Generate\Permissions\Assertion;

use Generate\Entity\Generation;
use Laminas\Permissions\Acl\Acl;
use Laminas\Permissions\Acl\Assertion\AssertionInterface;
use Laminas\Permissions\Acl\Resource\ResourceInterface;
use Laminas\Permissions\Acl\Role\RoleInterface;

class IsNotSubmitted implements AssertionInterface
{
    public function assert(
        Acl $acl,
        RoleInterface $role = null,
        ResourceInterface $resource = null,
        $privilege = null
    ) {
        if (!$resource instanceof Generation) {
            return false;
        }
        return !$resource->getSubmitted();
    }
}
