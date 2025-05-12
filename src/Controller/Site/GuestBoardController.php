<?php declare(strict_types=1);

namespace Generate\Controller\Site;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class GuestBoardController extends AbstractActionController
{
    public function indexAction()
    {
        $params = $this->params()->fromRoute();
        $params['action'] = 'browse';
        return $this->forward()->dispatch('Generate\Controller\Site\GuestBoard', $params);
    }

    public function browseAction()
    {
        // TODO Clarify how to show anonymous deposit via token.

        $user = $this->identity();
        if (!$user) {
            return $this->redirect()->toRoute('top', [], true);
        }

        $query = $this->params()->fromQuery();
        $query['owner_id'] = $user->getId();

        // TODO Add the full browse mechanism for the display of generations in guest board.
        if (!isset($query['per_page'])) {
            $query['per_page'] = 100;
        }

        $generations = $this->api()->search('generations', $query)->getContent();

        $view = new ViewModel([
            'site' => $this->currentSite(),
            'user' => $user,
            'generations' => $generations,
            'space' => 'guest',
        ]);
        return $view
            ->setTemplate('guest/site/guest/generation-browse');
    }

    public function showAction()
    {
        $params = $this->params()->fromRoute();
        $params['controller'] = 'Generate\Controller\Site\Generation';
        $params['__CONTROLLER__'] = 'generation';
        $params['resource'] = 'generation';
        $params['space'] = 'guest';
        return $this->forward()->dispatch('Generate\Controller\Site\Generation', $params);
    }

    /**
     * @deprecated Use show.
     */
    public function viewAction()
    {
        $params = $this->params()->fromRoute();
        $params['controller'] = 'Generate\Controller\Site\Generation';
        $params['__CONTROLLER__'] = 'generation';
        $params['resource'] = 'generation';
        $params['action'] = 'show';
        $params['space'] = 'guest';
        return $this->forward()->dispatch('Generate\Controller\Site\Generation', $params);
    }

    public function addAction()
    {
        $params = $this->params()->fromRoute();
        $params['controller'] = 'Generate\Controller\Site\Generation';
        $params['__CONTROLLER__'] = 'generation';
        $params['resource'] = 'generation';
        $params['space'] = 'guest';
        return $this->forward()->dispatch('Generate\Controller\Site\Generation', $params);
   }

   public function editAction()
   {
       $params = $this->params()->fromRoute();
       $params['controller'] = 'Generate\Controller\Site\Generation';
       $params['__CONTROLLER__'] = 'generation';
       $params['resource'] = 'generation';
       $params['space'] = 'guest';
       return $this->forward()->dispatch('Generate\Controller\Site\Generation', $params);
   }

   public function deleteConfirmAction()
   {
       $params = $this->params()->fromRoute();
       $params['controller'] = 'Generate\Controller\Site\Generation';
       $params['__CONTROLLER__'] = 'generation';
       $params['resource'] = 'generation';
       $params['space'] = 'guest';
       return $this->forward()->dispatch('Generate\Controller\Site\Generation', $params);
   }

   public function deleteAction()
   {
       $params = $this->params()->fromRoute();
       $params['controller'] = 'Generate\Controller\Site\Generation';
       $params['__CONTROLLER__'] = 'generation';
       $params['resource'] = 'generation';
       $params['space'] = 'guest';
       return $this->forward()->dispatch('Generate\Controller\Site\Generation', $params);
   }

   public function submitAction()
   {
       $params = $this->params()->fromRoute();
       $params['controller'] = 'Generate\Controller\Site\Generation';
       $params['__CONTROLLER__'] = 'generation';
       $params['resource'] = 'generation';
       $params['space'] = 'guest';
       return $this->forward()->dispatch('Generate\Controller\Site\Generation', $params);
   }
}
