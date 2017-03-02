<?php

namespace Pad4pm\ProgramBundle\Controller;

#symfony files
use JMS\DiExtraBundle\Annotation\Inject;
use JMS\DiExtraBundle\Annotation\InjectParams;
use JMS\SecurityExtraBundle\Annotation\Secure;
use SensioLabs\Security\SecurityChecker;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Doctrine\ORM\EntityManager;

#common files
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Pad4pm\CommonBundle\DependencyInjection\Constants;
use Pad4pm\CommonBundle\DTO\UI\BaseParametersUI;
use Pad4pm\CommonBundle\Services\SessionService;
use Pad4pm\UserBundle\Services\NewGrantedService;

#program files
use Pad4pm\ProgramBundle\Entity\Program;
use Pad4pm\ProgramBundle\Form\ProgramFilterType;
use Pad4pm\ProgramBundle\Form\ProgramType;
use Pad4pm\ProgramBundle\Services\WorkUnit\ProgramWorkUnit;
use Pad4pm\ProgramBundle\Services\WorkshopService;
use Pad4pm\ProgramBundle\Services\WorkUnit\WorkGroupWorkUnit;
use Pad4pm\ProgramBundle\Services\WorkUnit\WorkshopWorkUnit;


/**
 * ProgramManagementController
 * @Route("/program")
 */
class ProgramManagementController extends Controller
{
    protected static $KEYPARAM = "program_crud";
    protected static $PAGE = 1;
    protected static $COLUMN = "title";
    protected static $ORDER = "asc";
    protected static $NBPERPAGE = 50;
    protected static $KEYFILTER = "program_filter";

    private $programWorkUnit;
    private $workshopWorkUnit;
    private $workGroupWorkUnit;
    private $workshopService;
    private $grantedService;
    private $sessionService;
    private $em;

    /**
     * @InjectParams({
     *      "programWorkUnit" = @Inject("program.workunit"),
     *      "workshopWorkUnit" = @Inject("workshop.workunit"),
     *      "workGroupWorkUnit" = @Inject("workGroup.workunit"),
     *      "workshopService" = @Inject("workshop_service"),
     *      "sessionService" = @Inject("session.service"),
     *      "em" = @Inject("doctrine.orm.entity_manager"),
     *
     * })
     * @param ProgramWorkUnit $programWorkUnit
     * @param WorkshopWorkUnit $workshopWorkUnit
     * @param WorkGroupWorkUnit $workGroupWorkUnit
     * @param WorkshopService $workshopService
     * @param SessionService $sessionService
     * @param EntityManager $em
     */
    public function __construct($programWorkUnit, WorkshopWorkUnit $workshopWorkUnit, WorkGroupWorkUnit $workGroupWorkUnit, WorkshopService $workshopService, SessionService $sessionService, $em)
    {
        $this->programWorkUnit = $programWorkUnit;
        $this->workshopWorkUnit = $workshopWorkUnit;
        $this->workGroupWorkUnit = $workGroupWorkUnit;
        $this->workshopService = $workshopService;
        $this->sessionService = $sessionService;
        $this->grantedService = new NewGrantedService();
        $this->em = $em;

        #$programService->setProgramStateByCron();

    }

    /**
     * PROGRAM_MANAGE_LIST
     * PROGRAM_CLI_List
     * Index (forward to listAction)
     * @Route("/{page}/{column}/{order}", requirements={"page" = "\d+"}, defaults={ "page" = 1 ,"column" = "title","order" = "asc"} ,name="program")
     * @Template("Pad4pmProgramBundle:Program:listProgram.html.twig")
     * @param null $page
     * @param null $column
     * @param null $order
     * @param Request $request
     * @return array
     */
    public function indexProgramAction($page = null, $column = null, $order = null, Request $request)
    {
        $parameters = new BaseParametersUI(self::$KEYPARAM, self::$PAGE, self::$COLUMN, self::$ORDER, self::$NBPERPAGE);
        $this->sessionService->setParameters(self::$KEYPARAM, $page, $column, $order);


        $customer_id = $this->get('security.token_storage')->getToken()->getUser()->getCustomer()->getId();
        $user = $this->get('security.token_storage')->getToken()->getUser();
        $author_id = $this->get('security.token_storage')->getToken()->getUser()->getId();

        #It manage the filter
        $filterForm = $this->filter($request);

        #Get the settings after reclaim filters because Filter may deliver page 1
        $parameters = $this->sessionService->getParameters($parameters);

        if ($this->isGranted(Constants::ROLE_ADMIN)) {
            $customer_id = null;
            $author_id = null;
        }elseif($this->isGranted(Constants::ROLE_CUSTOMER) || $this->isGranted(Constants::ROLE_KM)){
            $author_id = null;
        }

        $program = $this->programWorkUnit->getProgramPagedList($filterForm->getData(), $parameters, $customer_id, $author_id, $user);

        return array(
            'parameters' => $parameters,
            'listProgramPaginated' => $program,
            'filter_form' => $filterForm->createView(),
            'filter_form_name' => $filterForm->getName(),
            'array_state' => $this->getStateTranslate(),
        );
    }

    /**
     * PROGRAM_MANAGE_FILTER
     * PROGRAM_CLI_Filter
     * Applies the filter for program
     * @param Request $request
     * @return \Symfony\Component\Form\Form
     */
    protected function filter(Request $request)
    {
        $session = $request->getSession();
        $user = $this->get('security.token_storage')->getToken()->getUser();


        $filter_form = $this->createForm(new ProgramFilterType($user));
        if ($request->getMethod() == 'POST' && $request->get('filter_action') == 'reset') {

            $session->remove('program_filters');
            $entity_params = $session->get('program_params');
            $entity_params['page'] = 1;
            $session->set('program_params', $entity_params);
        }

        if ($request->getMethod() == 'POST' && $request->get('filter_action') == 'filter') {

            $requestFilterData = $request->get('xtlpad4pm_ProgramBundle_programfiltertype');

            if (array_key_exists('customer_id', $requestFilterData) && $requestFilterData['customer_id'] != '') {

                $customer = null;

                $customerId = $requestFilterData['customer_id'];
                $customer = $this->get('customer.repository')->getCustomerById($customerId);

                if ($customer != null) {

                    $newValue['customer'] = $customer;
                }

                $newValue['customer_id'] = $requestFilterData['customer_id'];
                $newValue['title'] = $requestFilterData['title'];
                $newValue['state'] = $requestFilterData['state'];

                $request->request->set('xtlpad4pm_ProgramBundle_programfiltertype', $newValue);
            }

            $filter_form->submit($request);

            if ($filter_form->isValid()) {

                $filter_data = $filter_form->getData();
                $session->set('program_filters', $filter_data);

            }
        } else {
            if ($session->has('program_filters')) {

                $filter_data = $session->get('program_filters');

                if (!empty($filter_data['customer'])) {
                    $filter_data['customer'] = $this->em->merge($filter_data['customer']);
                }

                $filter_form = $this->createForm(new ProgramFilterType($user), $filter_data);
            }
        }
        return $filter_form;
    }

    /**
     * Program state translate
     * @return array
     */
    public function getStateTranslate()
    {
        $array_state = array();
        $array_state[Constants::PROGRAM_STATE_CREATED] = $this->get('translator')->trans(Constants::PROGRAM_STATE_CREATED, array(), 'db_messages');
        $array_state[Constants::PROGRAM_STATE_ONGOING] = $this->get('translator')->trans(Constants::PROGRAM_STATE_ONGOING, array(), 'db_messages');
        $array_state[Constants::PROGRAM_STATE_FINISHED] = $this->get('translator')->trans(Constants::PROGRAM_STATE_FINISHED, array(), 'db_messages');
        return $array_state;
    }

    /**
     * ws state translate
     * @return array
     */
    public function getWSStateTranslate()
    {
        $array_state = array();
        $array_state[Constants::WS_STATE_CREATED] = $this->get('translator')->trans(Constants::WS_STATE_CREATED, array(), 'db_messages');
        $array_state[Constants::WS_STATE_CLOSE] = $this->get('translator')->trans(Constants::WS_STATE_CLOSE, array(), 'db_messages');
        $array_state[Constants::WS_STATE_OPEN] = $this->get('translator')->trans(Constants::WS_STATE_OPEN, array(), 'db_messages');
        $array_state[Constants::WS_STATE_PURGED] = $this->get('translator')->trans(Constants::WS_STATE_PURGED, array(), 'db_messages');
        return $array_state;
    }

    /**
     * PROGRAM_MANAGE_CREATE
     * PROGRAM_CLI_Create
     * Create space for a program space
     * @Route("/create", name="program_create")
     * @Template("Pad4pmProgramBundle:Program:createProgram.html.twig")
     * @param Request $request
     * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function createProgramAction(Request $request)
    {
        $user = $this->get('security.token_storage')->getToken()->getUser();
        $customer = $this->get('security.token_storage')->getToken()->getUser()->getCustomer();

        if (!$this->grantedService->isAccessProgramRight($user, null, Constants::GRANTED_PROGRAM_CREATE)) {
            $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans("program.flash.ERR_account_error", array()));
            return $this->redirect($this->generateUrl('program'));
        }

        $formObject = $this->programWorkUnit->createProgramFormObject();

        if ($this->isGranted(Constants::ROLE_ADMIN)) {
            $form = $this->createForm(new ProgramType(true, true), $formObject);//check
        } else {
            $form = $this->createForm(new ProgramType(true, false, $customer), $formObject);//check
        }

        if ($request->getMethod() == 'POST') {
            $form->submit($request);

            if ($form->isValid()) {

                if ($this->isGranted(Constants::ROLE_ADMIN)) {
                    $customerId = $request->request->get("Pad4pm_ProgramBundle_programtype")['customer'];
                    $customer = $this->get('customer.repository')->getCustomerById($customerId);

                    $this->programWorkUnit->createProgram($form->getData(), $user, $customer);
                } else {

                    $this->programWorkUnit->createProgram($form->getData(), $user, $customer);

                }
                #Display a flash success Message
                $this->get('session')->getFlashBag()->add('notice', $this->get('translator')->trans("program.flash.ALT_create_success"));

                return $this->redirect($this->generateUrl('program'));

            } else {
                #Display a flash error message
                $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans("program.flash.ERR_create_error", array()));
            }
        }

        return array(
            'form' => $form->createView(),
            'form_name' => $form->getName()
        );
    }

    /**
     * Auto complete program title
     *
     * @Route("/autocomplete_title", name="program_ajax_title")
     * @param Request $request
     * @return JsonResponse
     */
    public function ajaxAutoCompleteTitleAction(Request $request)
    {

        $search_string = $request->get('value');
        $customer = $this->get('security.token_storage')->getToken()->getUser()->getCustomer();

        $customer_id = $customer->getId();
        #$author_id = $this->get('security.token_storage')->getToken()->getUser()->getId();
        $author_id = null;

        if ($this->isGranted(Constants::ROLE_ADMIN)) {
            $customer_id = null;
        }

        $entities = $this->programWorkUnit->getArrayTitleSearchString($customer_id, $search_string, $author_id);
        return new JsonResponse($entities);
    }

    /**
     * PROGRAM_DELETE_POPUP
     * PROGRAM_CLI_Delete
     * AJAX popup removal
     * @Route("/popup_delete", name="program_pop_up_delete")
     * @Secure (roles="ROLE_ADMIN, ROLE_CUSTOMER, ROLE_WSMANAGER")
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function loadDeletePopupAction(Request $request)
    {
        $id = $request->get('id');
        $user = $this->get('security.token_storage')->getToken()->getUser();
        $program = $this->programWorkUnit->getProgramDetailById($id);

        if (!$this->grantedService->isAccessProgramRight($user, $program, Constants::GRANTED_PROGRAM_DELETE)) {
            $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans("program.flash.ERR_account_error", array()));
            return $this->redirect($this->generateUrl('program'));
        }

        $programUI = null;
        if (!is_null($id)) {
            try {
                $programUI = $this->programWorkUnit->getProgramById($id);
            } catch (\Exception $e) {
                $programUI = null;
            }
        }

        return $this->render('Pad4pmProgramBundle:Program/others:modal_delete.html.twig', array(
            'program' => $programUI
        ));
    }

    /**
     * PROGRAM_MANAGE_DELETE
     * PROGRAM_CLI_Delete
     * Delete the program
     * @Route("/delete/{id}", name="program_delete")
     * @param Request $request
     * @param $id
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function deleteProgramAction(Request $request, $id)
    {
        $program = $this->programWorkUnit->getProgramDetailById($id);
        $user = $this->get('security.token_storage')->getToken()->getUser();

        if (!$this->grantedService->isAccessProgramRight($user, $program, Constants::GRANTED_PROGRAM_DELETE)) {
            $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans("program.flash.ERR_account_error", array()));
            return $this->redirect($this->generateUrl('program'));
        }

        try {
            $programUI = $this->programWorkUnit->deleteProgram($id);
            #Displays a flash succes Message
            $this->get('session')->getFlashBag()->add('notice', $this->get('translator')->trans("program.flash.ALT_delete_success", array('%program%' => $programUI->title)));
        } catch (\Exception $e) {
            #Displays a flash error Message
            $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans("program.flash.ERR_delete_error", array()));
        }
        return $this->redirect($this->generateUrl('program'));
    }

    /**
     * Show a program
     * @Route("/show/{id}", name="program_show")
     * @Template("Pad4pmProgramBundle:Program:showProgram.html.twig")
     * @param $id
     * @return array
     */
    public function showProgramAction($id)
    {
        $programDetails = $this->programWorkUnit->getProgramDetails($id);

        $user = $this->get('security.token_storage')->getToken()->getUser();

        #access right check
        if (!$this->grantedService->isAccessProgramRight($user, $programDetails, Constants::GRANTED_PROGRAM_SHOW)) {
            $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans("program.flash.ERR_account_error", array()));
            return $this->redirect($this->generateUrl('program'));
        }

        $workGroupArray = $this->workGroupWorkUnit->getWorkGroupArray($programDetails, $user);
        $workshopArray = $this->workshopWorkUnit->getWorkshopArray($programDetails,$id);

        #workshop dependency
        $workshopDependency = $this->workshopWorkUnit->getWorkshopDependencyData($programDetails, $user->getId());
        $ganttStartEndDates = $this->workshopWorkUnit->getGanttStartEndDateForProgram($programDetails);

        $ganttScaleArray = array('mode' => $this->get('session')->get('ganttZoomMode'), 'zoom' => $this->get('translator')->trans("program.show.gantt.scale.zoom"), 'default' => $this->get('translator')->trans("program.show.gantt.scale.default")) ;
        $programFilter = $this->get('session')->get('programFilter');

        $countryCode = $this->get('session')->get('countrycode');

        $ganttTranslations = array(
            'tooltip'=> array(
                'title' => $this->get('translator')->trans("program.show.gantt.tooltip.wsTitle"),
                'date'=>$this->get('translator')->trans("program.show.gantt.tooltip.wsDate"),
                'state' => $this->get('translator')->trans("program.show.gantt.tooltip.wsState"),
                'wg' => $this->get('translator')->trans("program.show.gantt.tooltip.wsWorkgroup")),
            'deleteModal'=>array(
                'from' => $this->get('translator')->trans("program.show.gantt.deleteModal.from"),
                'to' => $this->get('translator')->trans("program.show.gantt.deleteModal.to"),
                'link' => $this->get('translator')->trans("program.show.gantt.deleteModal.link"),
                'msg' => $this->get('translator')->trans("program.show.gantt.deleteModal.msg"),
                'cancel' => $this->get('translator')->trans("program.show.gantt.deleteModal.cancel"),
                'delete' => $this->get('translator')->trans("program.show.gantt.deleteModal.delete")
            ));

        return array(
            'programDetails' => $programDetails,
            'workGroupDetails' => $workGroupArray,
            'workshopDetails' => $workshopArray,
            'workshopDependencyData' => $workshopDependency['ganttData']['data'],
            'workshopDependencyLink' => $workshopDependency['ganttData']['links'],
            'workshopLinks' => $workshopDependency['link'],
            'ganttStartEndDates' => $ganttStartEndDates,
            'currentUserId'=> $user->getId(),
            'programAuthorId'=> $programDetails->getAuthor()->getId(),
            'array_state' => $this->getWSStateTranslate(),
            'ganttScaleArray' => $ganttScaleArray,
            'programFilter' => $programFilter,
            'ganttTranslations' => $ganttTranslations,
            'countryCode' => $countryCode
        );
    }

    /**
     * PROGRAM_MANAGE_EDIT
     * PROGRAM_CLI_Edit
     * Edit a program
     * @Secure (roles="ROLE_ADMIN, ROLE_CUSTOMER, ROLE_WSMANAGER")
     * @Route("/edit/{id}", name="program_edit")
     * @Template("Pad4pmProgramBundle:Program:editProgram.html.twig")
     * @param Request $request
     * @param $id
     * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function editProgramAction(Request $request, $id)
    {
        $program = $this->programWorkUnit->getProgramDetailById($id);
        $user = $this->get('security.token_storage')->getToken()->getUser();
        $customer = $this->get('security.token_storage')->getToken()->getUser()->getCustomer();

        if (!$this->grantedService->isAccessProgramRight($user, $program, Constants::GRANTED_PROGRAM_EDIT)) {
            $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans("program.flash.ERR_account_error", array()));
            return $this->redirect($this->generateUrl('program_show', array('id' => $id)));
        }

        $formObject = $this->programWorkUnit->editProgramFormObject($id);
        if ($this->isGranted(Constants::ROLE_ADMIN)) {
            $form = $this->createForm(new ProgramType(false, true), $formObject);//check
        } else {
            $form = $this->createForm(new ProgramType(false, false, $customer), $formObject);//check
        }

        if ($request->getMethod() == 'POST') {
            $form->submit($request);

            if ($form->isValid()) {
                if ($this->get('security.authorization_checker')->isGranted(Constants::ROLE_ADMIN)) {
                    $customerId = $request->request->get("Pad4pm_ProgramBundle_programtype")['customer'];
                    $customer = $this->get('customer.repository')->getCustomerById($customerId);

                    $this->programWorkUnit->updateProgram($form->getData(), $user, $customer, $id);
                } else {
                    $this->programWorkUnit->updateProgram($form->getData(), $user, $user->getCustomer(), $id);
                }
                #Display a flash success Message
                $this->get('session')->getFlashBag()->add('notice', $this->get('translator')->trans("program.flash.ALT_update_success"));

                return $this->redirect($this->generateUrl('program'));

            } else {
                #Display a flash error message
                $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans("program.flash.ERR_update_error", array()));
            }
        }

        return array(
            'program_id' => $id,
            'form' => $form->createView(),
            'form_name' => $form->getName()
        );
    }

    /**
     * WS_REMOVE_POPUP
     * WS_CLI_Remove.cs
     * AJAX popup removal for workshop in program
     * @Route("/workshop_popup_remove", name="workshop_pop_up_remove")
     * @Secure (roles="ROLE_ADMIN, ROLE_CUSTOMER, ROLE_WSMANAGER")
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function loadWorkshopRemovePopupAction(Request $request)
    {
        $workshopId = $request->get('id');

        $programId = $request->get('programId');
        $program = $this->programWorkUnit->getProgramDetailById($programId);
        $user = $this->get('security.token_storage')->getToken()->getUser();

        /*if (!$this->grantedService->isAccessProgramRight($user, $program, Constants::GRANTED_PROGRAM_WORKSHOP)) {
            $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans("program.flash.ERR_account_error", array()));
            return $this->redirect($this->generateUrl('program_show', array('id' => $programId)));
        }*/
        $workshopUI = null;
        if (!is_null($workshopId)) {
            try {
                $workshopUI = $this->workshopWorkUnit->getWorkshopUIById($workshopId);
            } catch (\Exception $e) {
                $workshopUI = null;
            }
        }


        return $this->render('Pad4pmProgramBundle:Program/others:modal_workshop_remove.html.twig', array(
            'workshop' => $workshopUI
        ));
    }

    /**
     * WS_MANAGE_REMOVE
     * WS_CLI_Remove
     * Remove workshop from program and delete all its work groups
     * @Secure (roles="ROLE_ADMIN, ROLE_CUSTOMER, ROLE_WSMANAGER")
     * @Route("/workshop_remove/{programId}/{workshopId}", name="workshop_remove")
     * @param Request $request
     * @param $workshopId
     * @param $programId
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function removeWorkshopAction(Request $request, $workshopId, $programId)
    {
        $user = $this->get('security.token_storage')->getToken()->getUser();
        $program = $this->programWorkUnit->getProgramDetailById($programId);
        $workshop = $this->workshopWorkUnit->getWorkshopById($workshopId);

        if (!$this->grantedService->isAccessProgramRight($user, $program, Constants::GRANTED_PROGRAM_WORKSHOP)) {
            $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans("program.flash.ERR_account_error", array()));
            return $this->redirect($this->generateUrl('program_show', array('id' => $programId)));
        }

        try {

            $workshopState = $workshop->getState();
            if($workshopState != Constants::WS_STATE_CREATED) {
                #display error message as ws state is not created
                $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans("program.workshop.flash.ERR_delete_state", array('%workshop_state%' => $workshopState)));
            }else{
                $workshopUI = $this->workshopWorkUnit->removeWorkshop($workshop);
                #display success message
                $this->get('session')->getFlashBag()->add('notice', $this->get('translator')->trans("program.workshop.flash.ALT_delete_success", array('%workshop%' => $workshopUI->title)));
            }

        } catch (\Exception $e) {
            #Displays a flash error Message
            $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans("program.workshop.flash.ERR_delete_error", array()));
        }
        return $this->redirect($this->generateUrl('program_show', array('id' => $programId)));
    }


    /**
     * @Route("/workshops/{programId}", name="program_workshops")
     * @param Request $request
     * @param $programId
     * @return Response
     */
    public function showWorkshopsAction(Request $request, $programId)
    {
        $userId = $this->getUser()->getId();
        $countryCode = $this->get('session')->get('countrycode');
        $timezone = $this->get('session')->get('timezone');

        $program = $this->programWorkUnit->getProgramDetails($programId);
        $eligibleWorkshops = $this->workshopWorkUnit->getEligibleSourceWorkshopsUI($programId, $userId, $countryCode, $timezone);
        $selectedWorkshop = $program->getWorkshops();

        $viewParams = [
            "eligibleWorkshops" => $eligibleWorkshops,
            // "selectedWorkshops" => $selectedWorkshop,
            "program" => $program
        ];

        return $this->render('Pad4pmProgramBundle:Program:addWorkshops.html.twig', $viewParams);
    }

    /**
     * add workshops in program
     * @Route("/addWorkshops/{programId}", name="program_add_workshops")
     * @Template()
     * @param Request $request
     * @param $programId
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function addWorkshopsAction(Request $request, $programId)
    {
        $workshopData = $request->request->all();
        $program = $this->programWorkUnit->getProgramDetails($programId);
        $user = $this->get('security.context')->getToken()->getUser();

        if (!$this->grantedService->isAccessProgramRight($user, $program, Constants::GRANTED_PROGRAM_WORKSHOP)) {
            $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans("program.flash.ERR_account_error", array()));
            return $this->redirect($this->generateUrl('program_show', array('id' => $programId)));
        }

        $this->workshopWorkUnit->addWorkshopsInProgram($workshopData, $program);

        $this->get('session')->getFlashBag()->add('notice', $this->get('translator')->trans("program.workshop.add_workshops.flash.ALT_add_success"));

        return $this->redirect($this->generateUrl('program_show', array('id' => $programId)));
    }


    /**
     * add workshop gantt link in program
     * @Route("/addWorkshopGanttLink/{programId}", name="workshop_gantt_link_add")
     * @Template()
     * @param Request $request
     * @param $programId
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function addWorkshopGanttLink(Request $request, $programId){

        $workshopLink = $request->request->all();
        $program = $this->programWorkUnit->getProgramDetails($programId);
        $user = $this->get('security.context')->getToken()->getUser();

        if (!$this->grantedService->isAccessProgramRight($user, $program, Constants::GRANTED_PROGRAM_WORKSHOP)) {
            $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans("program.show.gantt.flash.ERR_account_error", array()));
            return $this->redirect($this->generateUrl('program_show', array('id' => $programId)));
        }

        try {
            $workshopGanttLink = $this->workshopWorkUnit->addWorkshopGanttLink($workshopLink, $program, $user);
            $ganttLinkId = $workshopGanttLink->getId();
            #Displays a flash succes Message
            $flashMsg = $this->get('translator')->trans("program.show.gantt.flash.ALT_create_success", array());
        } catch (\Exception $e) {
            #Displays a flash error Message
            $ganttLink = '';
            $flashMsg = $this->get('translator')->trans("program.show.gantt.flash.ERR_create_error", array());
        }
        $responseMsg = array('linkId' => $ganttLinkId, 'msg' => $flashMsg);
        return new JsonResponse($responseMsg);

    }

    /**
     * delete workshop gantt link in program
     * @Route("/deleteWorkshopGanttLink/{programId}", name="workshop_gantt_link_delete")
     * @Template()
     * @param Request $request
     * @param $programId
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function deleteWorkshopGanttLink(Request $request, $programId){

        $workshopLinkDetails = $request->request->all();

        $program = $this->programWorkUnit->getProgramDetails($programId);
        $user = $this->get('security.context')->getToken()->getUser();

        if (!$this->grantedService->isAccessProgramRight($user, $program, Constants::GRANTED_PROGRAM_WORKSHOP)) {
            $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans("program.show.gantt.flash.ERR_account_error", array()));
            return $this->redirect($this->generateUrl('program_show', array('id' => $programId)));
        }

        try {
            $this->workshopWorkUnit->deleteWorkshopGanttLink($workshopLinkDetails);
            #Displays a flash succes Message
            $flashMsg = $this->get('translator')->trans("program.show.gantt.flash.ALT_delete_success", array());
        } catch (\Exception $e) {
            #Displays a flash error Message
            $flashMsg = $this->get('translator')->trans("program.show.gantt.flash.ERR_delete_error", array());
        }

        return new JsonResponse($flashMsg);

    }

    /**
     * set zoom mode for gantt | filter for program
     * @Route("/setProgramFilter", name="program_filter")
     * @Template()
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function setSessionForZoomAndFilter(Request $request){

        if($request->get('flag') == 1){
            $this->get('session')->set('ganttZoomMode', $request->get('value'));
        }else{
            $this->get('session')->set('programFilter', $request->get('value'));
        }

        return new JsonResponse($request->get('value'));

    }

    /**
     * delete workshop gantt link in program
     * @Route("/workshopWorkGroupsGanttLink", name="workshop_workgroup_gantt_link")
     * @Template()
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function getWorkshopWorkGroupsForGantt(Request $request){

        $workshopId = $request->get('id');
        $workGroupArray = array();
        $getAllWG = $this->workshopWorkUnit->getWorkshopWorkGroups($workshopId);
        foreach($getAllWG as $entity){
            $workGroupArray[]=array('groupName' => $entity->getWorkGroup()->getGroupName(),
                                    'color' => $entity->getWorkGroup()->getColor()->getCode());
        }
        return new JsonResponse($workGroupArray);

    }


}
