<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Enrollment;
use AppBundle\Entity\EnrollmentOptionalService;
use AppBundle\Entity\PaymentType;
use AppBundle\Utilities\Utilities;
use DateTime;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class EnrollmentController extends Controller
{
    /**
     * @Route("/{_locale}/{slug}/iscrizioni/{eventId}/seleziona-corsa/", name="event_enrollment_select_race")
     */
    public function enrollmentSelectRaceAction(Request $request, $eventId)
    {
        /*
         * Step che gestisce scelta della corsa
         */

        // resetto eventuali valori intermedi salvati in sessione
        $session = $this->container->get('session');
        $session->clear();

        $em = $this->getDoctrine()->getManager();
        $event = $em->getRepository('AppBundle:Event')->find($eventId);
        if (empty($event)) {
            return $this->redirect($this->generateUrl('homepage'));
        }
        $races = $event->getRaces();
        if (count($races) == 0) {
            return $this->redirect($this->generateUrl('homepage'));
        }
        /** @var $race \AppBundle\Entity\Race */
        foreach ($races as $race) {
            if (count($race->getPaymentRules()) == 0 || count($race->getPaymentTypesForRace()) == 0) {
                return $this->redirect($this->generateUrl('homepage'));
            }
        }

        // salvo in sessione l'evento in modo che non possa essere cambiato e sono certo che sia stato validato
        $session->set('event', $event);

        foreach ($races as $race) {
            $now = new DateTime('now');
            $raceId = $race->getId();

            $dql = "SELECT rpr, pr
                FROM AppBundle:RacePaymentRule rpr
                LEFT JOIN rpr.paymentRule pr
                WHERE rpr.race = $raceId 
                ORDER BY rpr.fromDate ASC, rpr.toDate ASC, rpr.price ASC";
            $query = $em->createQuery($dql);
            /** @var $racePaymentRules \AppBundle\Entity\RacePaymentRule */
            $racePaymentRules = $query->getResult();

            $lowestPrice = null;
            /** @var $racePaymentRule \AppBundle\Entity\RacePaymentRule */
            foreach ($racePaymentRules as $racePaymentRule) {
                if ($racePaymentRule->gettoDate()->setTime(23, 59, 59) < $now) { // perché le regole sono valide fino alle 23:59
                } else {
                    if (is_null($lowestPrice) || $racePaymentRule->getPrice() < $lowestPrice) {
                        $lowestPrice = $racePaymentRule->getPrice();
                    }
                }
            }

            $race->lowestPrice = !empty($lowestPrice) ? $lowestPrice : 0;
        }

        return $this->render('AppBundle:Enrollment:enrollment.html.twig', array(
            'event' => $event,
            'races' => $races,
            'formStepName' => 'selectRace'
        ));
    }

    /**
     * @Route("/{_locale}/{slug}/iscrizioni/{raceId}/informazioni-corsa/", name="event_enrollment_race_info")
     */
    public function enrollmentRaceInfoAction(Request $request, $raceId = null)
    {
        /*
         * Step che visualizza le informazioni generali di una corsa
         */

        $session = $this->container->get('session');
        $event = $session->get('event');
        if (empty($event) || empty($raceId)) {
            return $this->redirect($this->generateUrl('homepage'));
        }
        $em = $this->getDoctrine()->getManager();
        /** @var $race \AppBundle\Entity\Race */
        $race = $em->getRepository('AppBundle:Race')->find($raceId);
        if (empty($race) || $race->getEvent()->getId() != $event->getId()) {
            return $this->redirect($this->generateUrl('homepage'));
        }

        // salvo in sessione la corsa
        $session->set('race', $race);

        // controllo apertura iscrizioni
        $now = new DateTime('now');
        $enrollmentClose = false;
        $notOpen = false;
        if ($race->getEnrollmentEndDate() < $now ||
            $race->getEnrollmentsAreClosed() == true ||
            $race->getEnrollments()->count() >= $race->getMaxEnrollment()) {
            $enrollmentClose = true;
        } else if ($race->getEnrollmentStartDate() > $now) {
            $enrollmentClose = true;
            $notOpen = true;
        } else {
            $session->set('enrollmentOpen', true);
        }

        $dql = "SELECT rpr, pr
                FROM AppBundle:RacePaymentRule rpr
                LEFT JOIN rpr.paymentRule pr
                WHERE rpr.race = $raceId 
                ORDER BY rpr.fromDate ASC, rpr.toDate ASC, rpr.price ASC";
        $query = $em->createQuery($dql);
        /** @var $racePaymentRules \AppBundle\Entity\RacePaymentRule */
        $racePaymentRules = $query->getResult();
        /** @var $racePaymentRule \AppBundle\Entity\RacePaymentRule */
        foreach ($racePaymentRules as $racePaymentRule) {
            if ($racePaymentRule->gettoDate()->setTime(23, 59, 59) < $now) { // perché le regole sono valide fino alle 23:59
                $racePaymentRule->old = true;
            }
        }

        return $this->render('AppBundle:Enrollment:enrollment.html.twig', array(
            'event' => $event,
            'race' => $race,
            'enrollmentClose' => $enrollmentClose,
            'notOpen' => $notOpen,
            'formStepName' => 'raceEnrollmentInfo',
            'racePaymentRules' => $racePaymentRules
        ));
    }

    /**
     * @Route("/{_locale}/{slug}/iscrizioni/{raceId}/informazioni-personali/", name="event_enrollment_personal_info")
     */
    public function enrollmentPersonalInfoAction(Request $request, $raceId = null)
    {
        /*
         * Step che gestisce i dati personali
         */

        $session = $this->container->get('session');
        $event = $session->get('event');
        $race = $session->get('race');
        $enrollmentOpen = $session->get('enrollmentOpen');
        $partialEnrollment = $session->get('partialEnrollment');
        if (empty($partialEnrollment)) {
            $partialEnrollment = [];
        }

        if (!empty($event) && !empty($race) && $enrollmentOpen) {

            $em = $this->getDoctrine()->getManager();
            /** @var $user \AppBundle\Entity\User */
            $user = $this->get('security.token_storage')->getToken()->getUser();
            /** @var $currentAthlete \AppBundle\Entity\Athlete */
            $currentAthlete = null; // atleta da utilizzare per popolare il form
            $showSelectAthlete = false;
            $defaultValues = [];

            if ($request->query->get('noAthlete') != 'yes') {
                // caso in cui l'utente sia un team menager
                if ($this->get('security.authorization_checker')->isGranted('ROLE_TEAM_MANAGER')) {
                    $team = $em->getRepository('AppBundle:Team')->findOneBy(array('userId' => $user));
                    // controllare sempre che l'istanza team esista visto che la sua creazione
                    // non è contestuale alla creazione dell'istanza
                    if ($team != null) {
                        if (!$team->getAthletes()->isEmpty()) {
                            $showSelectAthlete = true;
                            $currentAthlete = $team->getAthletes()->first();
                            // se l'id dell'atleta è passato in get controllo che l'atleta appartenga alla squadra
                            if ($request->query->get('athlete') != null) {
                                foreach ($team->getAthletes() as $athleteObj) {
                                    if ($athleteObj->getId() == $request->query->get('athlete')) {
                                        $currentAthlete = $athleteObj;
                                        $partialEnrollment = [];
                                        break;
                                    }
                                }
                            }
                        }
                    }
                } // caso in cui l'utente sia un atleta
                else if ($this->get('security.authorization_checker')->isGranted('ROLE_ATHLETE')) {
                    $currentAthlete = $em->getRepository('AppBundle:Athlete')->findOneBy(array('userId' => $user));
                }
                if (!empty($currentAthlete)) {
                    $session->set('currentAthleteId', $currentAthlete->getId());
                    $defaultValues['name'] = $currentAthlete->getName();
                    $defaultValues['surname'] = $currentAthlete->getSurname();
                    $defaultValues['gender'] = $currentAthlete->getGender();
                    $defaultValues['born'] = $currentAthlete->getBorn();
                    $defaultValues['nationality'] = $currentAthlete->getNationality();
                    $defaultValues['email'] = $currentAthlete->getUserId()->getEmail();
                }
            } else {
                $session->remove('currentAthleteId');
            }

            // popoloro valori di default da sessione se esistenti
            if (!empty($partialEnrollment['name'])) {
                $defaultValues['name'] = $partialEnrollment['name'];
            }

            if (!empty($partialEnrollment['surname'])) {
                $defaultValues['surname'] = $partialEnrollment['surname'];
            }

            if (!empty($partialEnrollment['gender'])) {
                $defaultValues['gender'] = $partialEnrollment['gender'];
            }

            if (!empty($partialEnrollment['born'])) {
                $defaultValues['born'] = $partialEnrollment['born'];
            }

            if (!empty($partialEnrollment['nationId'])) {
                $defaultValues['nationId'] = $partialEnrollment['nationId'];
            }

            if (!empty($partialEnrollment['email'])) {
                $defaultValues['email'] = $partialEnrollment['email'];
            }

            if (!empty($partialEnrollment['phone'])) {
                $defaultValues['phone'] = $partialEnrollment['phone'];
            }

            if ($race->getEnrollmentTaxCodeShow() && !empty($partialEnrollment['taxCode'])) {
                $defaultValues['taxCode'] = $partialEnrollment['taxCode'];
            }

            $formBuilder = $this->createFormBuilder($defaultValues)
                ->add('name', TextType::class,
                    array(
                        'label' => 'name',
                        'required' => true,
                        'constraints' => [new NotBlank(),
                            new Length(['max' => 250])]
                    )
                )
                ->add('surname', TextType::class,
                    array(
                        'label' => 'surname',
                        'required' => true,
                        'constraints' => [new NotBlank(),
                            new Length(['max' => 250])]
                    )
                )
                ->add('gender', ChoiceType::class,
                    array(
                        'choices' => array(
                            'male' => 'male',
                            'female' => 'female'
                        ),
                        'label' => 'gender',
                        'placeholder' => '',
                        'choices_as_values' => true,
                        'required' => true,
                        'constraints' => [new NotBlank(),
                            new Length(['max' => 250])]
                    )
                )
                ->add('born', DateType::class,
                    array(
                        'label' => 'born',
                        'years' => range(date('Y') - 100, date('Y')),
                        'format' => 'dd/MM/yyyy',
                        'placeholder' => '',
                        'required' => true,
                        'constraints' => [new NotBlank()]
                    )
                )
                ->add('nationality', EntityType::class,
                    array(
                        'class' => 'AppBundle:Nationality',
                        'query_builder' => function (EntityRepository $er) {
                            return $er->createQueryBuilder('n')->orderBy('n.name', 'ASC');
                        },
                        'choice_label' => 'name',
                        'label' => 'nationality',
                        'placeholder' => '',
                        'required' => true,
                        'constraints' => [new NotBlank()]
                    )
                )
                ->add('email', RepeatedType::class,
                    array(
                        'type' => EmailType::class,
                        'first_options' => ['label' => 'email'],
                        'second_options' => ['label' => 'enrollment.confirmEmail'],
                        'required' => true,
                        'constraints' => [new NotBlank(),
                            new Length(['max' => 250]),
                            new Email(['checkMX' => true])]
                    )
                )
                ->add('phone', TextType::class,
                    array(
                        'label' => 'enrollment.phone',
                        'required' => true,
                        'constraints' => [new NotBlank(),
                            new Length(['max' => 250])]
                    )
                );

            if ($race->getEnrollmentTaxCodeShow()) {
                $constraints = array(new Length(['max' => 250]));
                if ($race->getEnrollmentTaxCodeMandatory()) {
                    array_push($constraints, new NotBlank());
                }
                $formBuilder
                    ->add('taxCode', TextType::class,
                        array(
                            'label' => 'enrollment.taxCode',
                            'required' => $race->getEnrollmentTaxCodeMandatory(),
                            'constraints' => $constraints
                        )
                    );
            }
            if ($showSelectAthlete) {
                $formBuilder->add('athletes', EntityType::class, array(
                        'mapped' => false,
                        'label' => 'enrollment.yourAthletes',
                        'class' => 'AppBundle:Athlete',
                        'query_builder' => function (EntityRepository $er) use ($currentAthlete) {
                            return $er->createQueryBuilder('a')
                                ->where('a.team =' . $currentAthlete->getTeam()->getId());
                        },
                        'data' => $em->getReference('AppBundle:Athlete', $currentAthlete->getId()),
                        'required' => true
                    )
                );
            }

            $form = $formBuilder->getForm();
            $form->handleRequest($request);
            if ($form->isValid()) {
                $personalData = $form->getData();

                $partialEnrollment['nationId'] = $form['nationality']->getData();
                $partialEnrollment = array_merge($partialEnrollment, $personalData);
                $partialEnrollment['raceId'] = $raceId;

                // salvo in sessione i dati fino a qui raccolti in modo tale da poterli utilizzare negli step successivi
                $session->set('partialEnrollment', $partialEnrollment);

                return $this->redirect($this->generateUrl('event_enrollment_additional_info', [
                    'raceId' => $raceId,
                    'slug' => $this->get('cocur_slugify')->slugify($event->getName())
                ]));
            } else {
                return $this->render('AppBundle:Enrollment:enrollment.html.twig', array(
                    'showSelectAthlete' => $showSelectAthlete,
                    'athlete' => $currentAthlete,
                    'form' => $form->createView(),
                    'event' => $event,
                    'race' => $race,
                    'formStepName' => 'personalInfo'
                ));
            }
        }
        return $this->redirect($this->generateUrl('homepage'));

    }

    /**
     * @Route("/{_locale}/{slug}/iscrizioni/{raceId}/informazioni-aggiuntive/", name="event_enrollment_additional_info")
     */
    public function enrollmentAdditionalInfoAction(Request $request, $raceId = null)
    {
        /*
         * Step che gestisce l'inserimento delle informazioni sulla squadra
         */

        $session = $this->container->get('session');
        $event = $session->get('event');
        $race = $session->get('race');
        $enrollmentOpen = $session->get('enrollmentOpen');
        $partialEnrollment = $session->get('partialEnrollment');
        $currentAthleteId = $session->get('currentAthleteId'); //usually is none

        if (!empty($event) && !empty($race) && $enrollmentOpen && !empty($partialEnrollment)) {
            $em = $this->getDoctrine()->getManager();
            /** @var $race \AppBundle\Entity\Race */
            $race = $em->getRepository('AppBundle:Race')->find($race->getId());
            if (!empty($currentAthleteId)) {
                $currentAthlete = $em->getRepository('AppBundle:Athlete')->find($currentAthleteId);
            }

            $currentCard = null; // tessera da utilizzare per popolare il form
            $showSelectCards = false;
            $defaultValues = [];

            // setto valore campo team
            if (!empty($currentAthlete)) {
                if ($currentAthlete->getTeam() != null) {
                    $defaultValues['team'] = $currentAthlete->getTeam()->getName();
                }
            }
            if (!empty($partialEnrollment['team'])) {
                $defaultValues['team'] = $partialEnrollment['team'];
            }

            // setto tipo di tessera e numero di tessera, solo se necessario
            if ($race->getEnrollmentCardInfoShow()) {
                if (!empty($currentAthlete) && !$currentAthlete->getCards()->isEmpty()) {
                    $currentCard = $currentAthlete->getCards()->first();
                    $showSelectCards = true;
                    // se l'id della tessera è passato in get controllo che l'atleta possieda tale tessera
                    if ($request->query->get('card') != null) {
                        foreach ($currentAthlete->getCards() as $cardObj) {
                            if ($cardObj->getId() == $request->query->get('card')) {
                                $currentCard = $cardObj;
                                break;
                            }
                        }
                    }
                    $defaultValues['cardType'] = $this->get('translator')->trans($currentCard->getType()->getType());
                    $defaultValues['cardNumber'] = $currentCard->getCardNumber();
                }

                if (!empty($partialEnrollment['cardType'])) {
                    $defaultValues['cardType'] = $partialEnrollment['cardType'];
                }

                if (!empty($partialEnrollment['cardNumber'])) {
                    $defaultValues['cardNumber'] = $partialEnrollment['cardNumber'];
                }
            }

            $formBuilder = $this->createFormBuilder($defaultValues)
                ->add('team', TextType::class,
                    array(
                        'label' => 'team',
                        'required' => false,
                        'constraints' => [new Length(['max' => 250])]
                    )
                );

            if ($race->getEnrollmentCardInfoShow()) {
                $constraints = array(new Length(['max' => 250]));
                if ($race->getEnrollmentCardInfoMandatory()) {
                    array_push($constraints, new NotBlank());
                }
                $formBuilder
                    ->add('cardType', TextType::class,
                        array(
                            'label' => 'cardType',
                            'required' => $race->getEnrollmentCardInfoMandatory(),
                            'constraints' => $constraints
                        )
                    )
                    ->add('cardNumber', TextType::class,
                        array(
                            'label' => 'cardNumber',
                            'required' => $race->getEnrollmentCardInfoMandatory(),
                            'constraints' => $constraints
                        )
                    );
            }

            //aggiungo al form eventuali campi opzionali
            if ($race->getEnrollmentOptionalFields() != null) {
                $this->addExtraFieldsToForm($request, $race, $formBuilder);
            }

            if ($showSelectCards) {
                foreach ($currentAthlete->getCards() as $cardObj) {
                    $cardObj->setTranslator($this->get('translator'));
                }
                $formBuilder
                    ->add('cards', EntityType::class,
                        array(
                            'mapped' => false,
                            'label' => 'enrollment.cards',
                            'class' => 'AppBundle:Card',
                            'query_builder' => function (EntityRepository $er) use ($currentAthlete) {
                                return $er->createQueryBuilder('c')
                                    ->where('c.athlete =' . $currentAthlete->getId());
                            },
                            'data' => $em->getReference('AppBundle:Card', $currentCard->getId()),
                            'required' => true,
                        )
                    );
            }

            $form = $formBuilder->getForm();
            $form->handleRequest($request);
            if ($form->isValid()) {
                try {
                    $additionalData = $form->getData();

                    //recupero i valori dei possibili campi opzionali
                    $extraFields = $this->getExtraFieldsFromForm($request, $race, $form);
                    $additionalData['otherInfo'] = $extraFields[0];
                    $additionalData['optionalFieldsArray'] = $extraFields[1];

                    $partialEnrollment = array_merge($partialEnrollment, $additionalData);

                    // salvo in sessione i dati fino a qui raccolti in modo tale da poterli utilizzare negli step successivi
                    $session->set('partialEnrollment', $partialEnrollment);

                    $optionalServices = $em->getRepository('AppBundle:RaceOptionalService')->findBy([
                        'race' => $race
                    ]);

                    if (!empty($optionalServices)) {
                        return $this->redirect($this->generateUrl('event_enrollment_optional_services', [
                            'raceId' => $raceId,
                            'slug' => $this->get('cocur_slugify')->slugify($event->getName())
                        ]));
                    } else {
                        if ($race->getEnrollmentMedicalExaminationShow()) {
                            return $this->redirect($this->generateUrl('event_enrollment_medical_examination', [
                                'raceId' => $raceId,
                                'slug' => $this->get('cocur_slugify')->slugify($event->getName())
                            ]));
                        } else {
                            return $this->redirect($this->generateUrl('event_enrollment_summary', [
                                'raceId' => $raceId,
                                'slug' => $this->get('cocur_slugify')->slugify($event->getName())
                            ]));
                        }
                    }
                } catch (\Exception $e) {
                    $session->getFlashBag()->add('danger', $this->get('translator')->trans('enrollment.KO'));
                }
            }

            return $this->render('AppBundle:Enrollment:enrollment.html.twig', array(
                'showSelectCards' => $showSelectCards,
                'enrollment' => $partialEnrollment,
                'form' => $form->createView(),
                'event' => $event,
                'race' => $race,
                'formStepName' => 'additionalInfo'
            ));
        }

        return $this->redirect($this->generateUrl('homepage'));
    }

    /**
     * @Route("/{_locale}/{slug}/iscrizioni/{raceId}/servizi-aggiuntivi/", name="event_enrollment_optional_services")
     */
    public function enrollmentActionOptionalServices(Request $request, $raceId = null)
    {
        /*
         * Step tutto opzionale per la selezione dei servizi aggiuntivi
         */

        $session = $this->container->get('session');
        $event = $session->get('event');
        $race = $session->get('race');
        $enrollmentOpen = $session->get('enrollmentOpen');
        $partialEnrollment = $session->get('partialEnrollment');

        if (!empty($event) && !empty($race) && $enrollmentOpen && !empty($partialEnrollment)) {
            $em = $this->getDoctrine()->getManager();
            /** @var $race \AppBundle\Entity\Race */
            $race = $em->getRepository('AppBundle:Race')->find($race->getId());

            $defaultValues = [];
            if (!empty($partialEnrollment['optionalServices'])) {
                foreach ($partialEnrollment['optionalServices'] as $optionalServiceId => $objValues) {
                    $defaultValues['optionalServiceQuantity' . $optionalServiceId] = $objValues->quantity;
                }
            }

            $formBuilder = $this->createFormBuilder($defaultValues);

            /** @var $optionalServices \AppBundle\Entity\RaceOptionalService */
            $optionalServices = $race->getRaceOptionalServices();

            /** @var $optionalService \AppBundle\Entity\RaceOptionalService */
            foreach ($optionalServices as $optionalService) {
                $formBuilder->add('optionalServiceQuantity' . $optionalService->getId(), IntegerType::class, array(
                    'label' => '',
                    'required' => false,
                    'constraints' => [
                        new NotBlank(),
                        new GreaterThanOrEqual(0),
                        new LessThanOrEqual(15)
                    ],
                ));
            }

            $form = $formBuilder->getForm();
            $form->handleRequest($request);
            if ($form->isValid()) {
                try {
                    $optionalServicesData = $form->getData();
                    $optionalServicesSelected = [];

                    foreach ($optionalServices as $optionalService) {
                        $key = 'optionalServiceQuantity' . $optionalService->getId();

                        if (!empty($optionalServicesData[$key])) {
                            $obj = new \stdClass();
                            $obj->price = $optionalService->getPrice();
                            $obj->description = $optionalService->getDescription();
                            $obj->quantity = $optionalServicesData[$key];

                            $optionalServicesSelected[$optionalService->getId()] = $obj;
                        }
                    }

                    // va fatto anche se vuoto per gestire caso in cui un utente torni indietro negli step e decida di rimuovere tutti i servizi opzionali
                    $partialEnrollment['optionalServices'] = $optionalServicesSelected;

                    // salvo in sessione i dati fino a qui raccolti in modo tale da poterli utilizzare negli step successivi
                    $session->set('partialEnrollment', $partialEnrollment);

                    if ($race->getEnrollmentMedicalExaminationShow()) {
                        return $this->redirect($this->generateUrl('event_enrollment_medical_examination', [
                            'raceId' => $raceId,
                            'slug' => $this->get('cocur_slugify')->slugify($event->getName())
                        ]));
                    } else {
                        return $this->redirect($this->generateUrl('event_enrollment_summary', [
                            'raceId' => $raceId,
                            'slug' => $this->get('cocur_slugify')->slugify($event->getName())
                        ]));
                    }
                } catch
                (\Exception $e) {
                    $session->getFlashBag()->add('danger', $this->get('translator')->trans('enrollment.KO'));
                }
            }

            return $this->render('AppBundle:Enrollment:enrollment.html.twig', array(
                'enrollment' => $partialEnrollment,
                'form' => $form->createView(),
                'event' => $event,
                'race' => $race,
                'formStepName' => 'optionalServices',
                'optionalServices' => $optionalServices
            ));
        }

        return $this->redirect($this->generateUrl('homepage'));
    }

    /**
     * @Route("/{_locale}/{slug}/iscrizioni/{raceId}/visita-medica/", name="event_enrollment_medical_examination")
     */
    public function enrollmentActionMedicalExamination(Request $request, $raceId = null)
    {
        /*
         * Step tutto opzionale per l'inserimento della visita medica
         */

        $session = $this->container->get('session');
        $event = $session->get('event');
        $race = $session->get('race');
        $enrollmentOpen = $session->get('enrollmentOpen');
        $partialEnrollment = $session->get('partialEnrollment');
        $currentAthleteId = $session->get('currentAthleteId'); //usually is none

        if (!empty($event) && !empty($race) && $enrollmentOpen && !empty($partialEnrollment)) {
            $em = $this->getDoctrine()->getManager();
            $validMedicalExamination = false;
            $currentAthlete = null;
            if (!empty($currentAthleteId)) {
                /** @var $currentAthlete \AppBundle\Entity\Athlete */
                $currentAthlete = $em->getRepository('AppBundle:Athlete')->find($currentAthleteId);
                if ($currentAthlete->getHasMedicalExamination() == true &&
                    $currentAthlete->getExpireMedicalExamination() > $race->getDate()) {
                    $validMedicalExamination = true;
                }
            }

            $session->set('validMedicalExamination', $validMedicalExamination);
            $defaultValues = [];

            if (!empty($partialEnrollment['medicalExaminationExpiration'])) {
                $defaultValues['medicalExaminationExpiration'] = $partialEnrollment['medicalExaminationExpiration'];
            }

            $formBuilder = $this->createFormBuilder($defaultValues);
            /** @var $race \AppBundle\Entity\Race */
            if (!$validMedicalExamination) {
                $constraints = array(new \Symfony\Component\Validator\Constraints\File([
                    'maxSize' => '5M',
                    'mimeTypes' => [
                        'application/pdf',
                        'application/x-pdf',
                        'image/png',
                        'image/jpeg',
                        'image/jpeg'
                    ]]));
                if ($race->getEnrollmentMedicalExaminationMandatory()) {
                    array_push($constraints, new NotBlank());
                }
                $formBuilder->add('medicalExamination', FileType::class,
                    array(
                        'label' => 'medicalExamination',
                        'required' => $race->getEnrollmentMedicalExaminationMandatory(),
                        'constraints' => $constraints
                    )
                );

                $constraints = [];
                if ($race->getEnrollmentMedicalExaminationMandatory()) {
                    array_push($constraints, new NotBlank());
                }
                $formBuilder->add('medicalExaminationExpiration', DateType::class,
                    array(
                        'label' => 'medicalExaminationExpiration',
                        'required' => $race->getEnrollmentMedicalExaminationMandatory(),
                        'years' => range(date('Y'), date('Y') + 1),
                        'format' => 'dd/MM/yyyy',
                        'data' => new \DateTime('now'),
                        'constraints' => $constraints
                    )
                );
            }

            $form = $formBuilder->getForm();
            $form->handleRequest($request);
            if ($form->isValid()) {
                try {
                    if (!$validMedicalExamination) {
                        $medicalExaminationData = $form->getData();
                        /** @var $file UploadedFile */
                        $file = $form['medicalExamination']->getData();
                        $date = new DateTime();
                        $tmpPath = '/tmp/' . $date->getTimestamp();
                        rename($file->getPathname(), $tmpPath);
                        $partialEnrollment['medicalExaminationExpiration'] = $medicalExaminationData['medicalExaminationExpiration'];
                        $partialEnrollment['medicalExaminationFilepath'] = $tmpPath;
                    } else {
                        $partialEnrollment['medicalExaminationExpiration'] = $currentAthlete->getExpireMedicalExamination();
                        $partialEnrollment['medicalExaminationFilepath'] = $currentAthlete->getMedicalExaminationPath();
                    }
                    // salvo in sessione i dati fino a qui raccolti in modo tale da poterli utilizzare negli step successivi
                    $session->set('partialEnrollment', $partialEnrollment);
                    return $this->redirect($this->generateUrl('event_enrollment_summary', [
                        'raceId' => $raceId,
                        'slug' => $this->get('cocur_slugify')->slugify($event->getName())
                    ]));
                } catch
                (\Exception $e) {
                    $session->getFlashBag()->add('danger', $this->get('translator')->trans('enrollment.KO'));
                }
            }

            return $this->render('AppBundle:Enrollment:enrollment.html.twig', array(
                'enrollment' => $partialEnrollment,
                'form' => $form->createView(),
                'event' => $event,
                'race' => $race,
                'athlete' => $currentAthlete,
                'validMedicalExamination' => $validMedicalExamination,
                'formStepName' => 'medicalExamination'
            ));
        }

        return $this->redirect($this->generateUrl('homepage'));
    }

    public function getRacePaymentRuleByGenderAndBornDate($race, $gender, $born)
    {
        $now = new DateTime();
        $em = $this->getDoctrine()->getManager();
        $raceId = $race->getId();

        $dql = "SELECT rpr, pr
                    FROM AppBundle:RacePaymentRule rpr
                    LEFT JOIN rpr.paymentRule pr
                    WHERE rpr.race = $raceId 
                    AND :now >= rpr.fromDate
                    AND :now <= rpr.toDate
                    ORDER BY pr.priority DESC, rpr.fromDate DESC";
        $query = $em
            ->createQuery($dql)
            ->setParameter('now', $now);

        /** @var $racePaymentRules \AppBundle\Entity\RacePaymentRule */
        $racePaymentRules = $query->getResult();
        $priceSet = false;
        $lastPriority = null;
        $lastValidRacePaymentRule = null;

        /** @var $racePaymentRule \AppBundle\Entity\RacePaymentRule */
        foreach ($racePaymentRules as $racePaymentRule) {
            /** @var $paymentRule \AppBundle\Entity\PaymentRule */
            $paymentRule = $racePaymentRule->getPaymentRule();

            if (!empty($paymentRule)) {
                if ($priceSet && !is_null($lastPriority) && $lastPriority > $paymentRule->getPriority()) {
                    // ho analizzato tutte le regole con la stessa priorità e ho settato un prezzo, quindi posso uscire
                    break;
                }

                $lastPriority = $paymentRule->getPriority();
                $enrollmentField = $paymentRule->getEnrollmentField();

                if ($enrollmentField === 'gender'
                    && $gender
                    && $gender == $racePaymentRule->getEnrollmentFieldValue()) {

                    $lastValidRacePaymentRule = $racePaymentRule;
                    $priceSet = true;
                } else if ($enrollmentField === 'born' && $born && !is_null($racePaymentRule->getEnrollmentFieldValue())) {
                    $yearsDiff = intval($race->getDate()->format('Y')) - intval($born->format('Y'));
                    if ($yearsDiff >= $racePaymentRule->getEnrollmentFieldValue()) {
                        $lastValidRacePaymentRule = $racePaymentRule;
                        $priceSet = true;
                    }
                }
            } else {
                // setto il prezzo ma resto nel ciclo perché in caso di regole sia con paymentRule che senza, vengono analizzate prima quelle senza
                $lastValidRacePaymentRule = $racePaymentRule;
            }
        }

        return $lastValidRacePaymentRule;
    }

    public function getOptionalServicesPrice($partialEnrollment)
    {
        $optionalServicesPrice = 0;
        if (!empty($partialEnrollment['optionalServices'])) {
            foreach ($partialEnrollment['optionalServices'] as $optionalServiceId => $optionalService) {
                if (!is_null($optionalService) && !is_null($optionalService->price)) {
                    $optionalServicesPrice += $optionalService->price * $optionalService->quantity;
                }
            }
        }

        return $optionalServicesPrice;
    }

    public function discountCodeValidationCallback($discountCode, ExecutionContextInterface $context)
    {
        if (!empty($discountCode)) {
            $session = $this->container->get('session');
            $partialEnrollment = $session->get('partialEnrollment');

            $raceDiscountCode = $this->getRaceDiscountCode($partialEnrollment['raceId'], $discountCode);

            if (empty($raceDiscountCode)) {
                $context
                    ->buildViolation($this->get('translator')->trans('raceDiscountCode.discountCodeNotValid'))
                    ->addViolation();
            }
        }
    }

    /**
     * @Route("/{_locale}/{slug}/iscrizioni/{raceId}/riepilogo/", name="event_enrollment_summary")
     */
    public function enrollmentActionSummary(Request $request, $raceId = null)
    {
        /*
         * Step per il calcolo del prezzo da applicare e la visualizzazione del riepilogo, più scelta metodo di pagamento
         */
        $session = $this->container->get('session');
        $event = $session->get('event');
        $race = $session->get('race');
        $enrollmentOpen = $session->get('enrollmentOpen');
        $partialEnrollment = $session->get('partialEnrollment');

        if (!empty($event) && !empty($race) && $enrollmentOpen && !empty($partialEnrollment)) {
            $em = $this->getDoctrine()->getManager();
            /** @var $race \AppBundle\Entity\Race */
            $race = $em->getRepository('AppBundle:Race')->find($race->getId());
            $raceId = $race->getId();
            /* calcolo il prezzo da far pagare */

            // prezzo base della gara
            $racePaymentRule = $this->getRacePaymentRuleByGenderAndBornDate($race, $partialEnrollment['gender'], $partialEnrollment['born']);
            $price = $racePaymentRule->getPrice();
            // prezzo dei servizi aggiuntivi
            $optionalServicesPrice = $this->getOptionalServicesPrice($partialEnrollment);

            $totalPrice = $price + $optionalServicesPrice;

            $partialEnrollment['racePrice'] = $price;
            $partialEnrollment['optionalServicesPrice'] = $optionalServicesPrice;
            $partialEnrollment['calculatedPrice'] = $totalPrice;
            $partialEnrollment['racePaymentRule'] = !empty($racePaymentRule) ? $racePaymentRule : null;

            /** @var $paymentTypes \AppBundle\Entity\PaymentType */
            $paymentTypes = $race->getPaymentTypesForRace();
            $formBuilder = $this->createFormBuilder()
                ->add('paymentMethod', ChoiceType::class, [
                    'choices' => $paymentTypes,
                    'choice_label' => function ($choice, $key, $value) {
                        return $this->get('translator')->trans('payment.' . $choice->getName());
                    },
                    'choice_value' => function (PaymentType $paymentType = null) {
                        return $paymentType ? $paymentType->getId() : '';
                    },
                    'label' => $this->get('translator')->trans('payment.paymentMethod'),
                    'empty_data' => $paymentTypes[0],
                    'required' => true
                ])
                ->add('discountCode', TextType::class,
                    array(
                        'label' => $this->get('translator')->trans('raceDiscountCode.addADiscountCode'),
                        'required' => false,
                        'constraints' => array(
                            new Callback([
                                $this,
                                'discountCodeValidationCallback'
                            ])
                        )
                    )
                );

            $form = $formBuilder->getForm();
            $form->handleRequest($request);
            if ($form->isValid()) {
                try {
                    $summaryData = $form->getData();
                    $partialEnrollment['paymentMethodId'] = $summaryData['paymentMethod'];
                    $partialEnrollment['discountCode'] = $summaryData['discountCode'];

                    // salvo in sessione i dati fino a qui raccolti in modo tale da poterli utilizzare negli step successivi
                    $session->set('partialEnrollment', $partialEnrollment);
                    return $this->redirect($this->generateUrl('event_enrollment_payment', [
                        'raceId' => $raceId,
                        'slug' => $this->get('cocur_slugify')->slugify($event->getName())
                    ]));
                } catch (\Exception $e) {
                    $session->getFlashBag()->add('danger', $this->get('translator')->trans('enrollment.KO'));
                }
            }

            return $this->render('AppBundle:Enrollment:enrollment.html.twig', array(
                'event' => $event,
                'race' => $race,
                'form' => $form->createView(),
                'formStepName' => 'summary',
                'enrollmentSummary' => $partialEnrollment
            ));
        }

        return $this->redirect($this->generateUrl('homepage'));
    }

    /**
     * @Route("/{_locale}/{slug}/iscrizioni/{raceId}/pagamento/", name="event_enrollment_payment")
     */
    public function enrollmentActionPayment(Request $request, $raceId = null)
    {
        /*
         * Step per la visualizzazione del riepilogo del pagamento completato
         */

        $session = $this->container->get('session');
        $event = $session->get('event');
        $race = $session->get('race');
        $enrollmentOpen = $session->get('enrollmentOpen');
        $partialEnrollment = $session->get('partialEnrollment');
        $validMedicalExamination = $session->get('validMedicalExamination');

        if (!empty($event) && !empty($race) && $enrollmentOpen && !empty($partialEnrollment)) {
            $em = $this->getDoctrine()->getManager();

            /** @var $race \AppBundle\Entity\Race */
            $race = $em->getRepository('AppBundle:Race')->find($race->getId());
            /** @var $nationality \AppBundle\Entity\Nationality */
            $nationality = $em->getRepository('AppBundle:Nationality')->find($partialEnrollment['nationId']);
            /** @var $paymentType \AppBundle\Entity\PaymentType */
            $paymentType = $em->getRepository('AppBundle:PaymentType')->find($partialEnrollment['paymentMethodId']);
            $user = $this->get('security.token_storage')->getToken()->getUser();

            /* ri-calcolo (per motivi di sicurezza) il prezzo comprensivo del metodo di pagamento,
               verificando che sia lo stesso valore presente in sessione, ovvero quello che l'utente ha confermato nella
                pagina precedente */

            // prezzo base della gara
            $racePaymentRule = $this->getRacePaymentRuleByGenderAndBornDate($race, $partialEnrollment['gender'], $partialEnrollment['born']);
            $price = $racePaymentRule->getPrice();
            // prezzo dei servizi aggiuntivi
            $optionalServicesPrice = $this->getOptionalServicesPrice($partialEnrollment);

            if ($price != $partialEnrollment['racePrice'] || $optionalServicesPrice != $partialEnrollment['optionalServicesPrice']) {
                $session->getFlashBag()->add('danger', $this->get('translator')->trans('enrollment.KOpriceCalc'));

                return $this->redirect($this->generateUrl('event_enrollment_summary', [
                    'raceId' => $raceId,
                    'slug' => $this->get('cocur_slugify')->slugify($event->getName())
                ]));
            }

            $totalPrice = $price;
            $partialEnrollment['subTotal'] = $totalPrice;

            // applico gli eventuali sconti
            if (!empty($partialEnrollment['discountCode'])) {
                /** @var $raceDiscountCode \AppBundle\Entity\RaceDiscountCode */
                $raceDiscountCode = $this->getRaceDiscountCode($raceId, $partialEnrollment['discountCode']);

                if (!empty($raceDiscountCode)) {
                    if (empty($raceDiscountCode->isGratis())) {
                        if (!empty($raceDiscountCode->getPercentage())) {
                            $discountAmount = $totalPrice * $raceDiscountCode->getAmount() / 100;
                        } else {
                            $discountAmount = $raceDiscountCode->getAmount();
                        }

                        $totalPrice -= $discountAmount;
                        $partialEnrollment['discountAmount'] = $discountAmount;
                    } else {
                        $totalPrice = 0;
                    }

                    $partialEnrollment['calculatedPriceWithDiscount'] = $totalPrice;
                }
            }

            $totalPrice += $optionalServicesPrice;

            $charges = 0;
            $secretarialCosts = 0;

            if ($paymentType->getName() == 'transfer') {
                $charges = $race->getTransferCharges();
                $secretarialCosts = $race->getTransferSecretarialCosts();
            } elseif ($paymentType->getName() == 'onsite') {
                $charges = $race->getOnsiteCharges();
                $secretarialCosts = $race->getOnsiteSecretarialCosts();
            }

            if (empty($raceDiscountCode) || !$raceDiscountCode->isGratis()) {
                $totalPrice += $charges + $secretarialCosts;
            } else {
                /* cambio metodo di pagamento dopo aver correttamente settato le commissioni settandolo a "onsite"
                    in modo che non ci sia la gestione del bonifico visto che il prezzo è a zero */
                $paymentType = $em->getRepository('AppBundle:PaymentType')->findOneBy([
                    'name' => 'onsite'
                ]);
            }

            $partialEnrollment['calculatedPrice'] = $totalPrice;
            $partialEnrollment['price'] = $price;
            $partialEnrollment['optionalServicesPrice'] = $optionalServicesPrice;
            $partialEnrollment['charges'] = $charges;
            $partialEnrollment['secretarialCosts'] = $secretarialCosts;

            /** @var $enrollment \AppBundle\Entity\Enrollment */
            $enrollment = new Enrollment();
            if ($user != 'anon.') {
                $enrollment->setUserId($user);
            }
            $enrollment->setName(mb_strtoupper($partialEnrollment['name'], 'UTF-8'));
            $enrollment->setSurname(mb_strtoupper($partialEnrollment['surname'], 'UTF-8'));
            $enrollment->setGender($partialEnrollment['gender']);
            $enrollment->setBorn($partialEnrollment['born']);
            $enrollment->setNationality($nationality);
            $enrollment->setEmail(mb_strtolower($partialEnrollment['email'], 'UTF-8'));
            $enrollment->setPhone($partialEnrollment['phone']);
            $enrollment->setTaxCode(isset($partialEnrollment['taxCode']) ? $partialEnrollment['taxCode'] : '');
            $enrollment->setRace($race);
            $enrollment->setTeam(mb_strtoupper($partialEnrollment['team'], 'UTF-8'));
            $enrollment->setCardType(isset($partialEnrollment['cardType']) ? mb_strtoupper($partialEnrollment['cardType'], 'UTF-8') : null);
            $enrollment->setCardNumber(isset($partialEnrollment['cardNumber']) ? $partialEnrollment['cardNumber'] : null);
            $enrollment->setOptionalFields(json_encode($partialEnrollment['optionalFieldsArray']));
            $enrollment->setPaymentMethod($paymentType);
            $enrollment->setCalculatedPrice($partialEnrollment['calculatedPrice']);
            $enrollment->setRacePrice($partialEnrollment['price']);
            $enrollment->setOptionalServicesPrice($partialEnrollment['optionalServicesPrice']);
            $enrollment->setCharges($partialEnrollment['charges']);
            $enrollment->setSecretarialCosts($partialEnrollment['secretarialCosts']);

            if (!empty($raceDiscountCode)) {
                $enrollment->setDiscountCode($raceDiscountCode);

                if (!empty($discountAmount)) {
                    $enrollment->setDiscountAmount($discountAmount);
                }

                // invalido codice sconto in modo che non possa più essere utilizzato
                $raceDiscountCode->setExpired(true);
                $em->persist($raceDiscountCode);
                $em->flush();
            }

            $em->persist($enrollment);
            $em->flush();

            if (!empty($partialEnrollment['optionalServices'])) {
                foreach ($partialEnrollment['optionalServices'] as $optionalServiceId => $objValues) {
                    /** @var $enrollmentOptionalService \AppBundle\Entity\EnrollmentOptionalService */
                    $enrollmentOptionalService = new EnrollmentOptionalService();
                    $enrollmentOptionalService->setEnrollment($enrollment);
                    $enrollmentOptionalService->setDescription($objValues->description);
                    $enrollmentOptionalService->setQuantity($objValues->quantity);
                    $enrollmentOptionalService->setPrice($objValues->price);

                    $em->persist($enrollmentOptionalService);
                    $em->flush();
                }
            }

            try {
                if ($validMedicalExamination == false) {
                    $date = new DateTime();
                    $file = new File($partialEnrollment['medicalExaminationFilepath']);
                    if ($file != null) {
                        $extension = $file->guessExtension();
                        if (!$extension) {
                            $extension = 'bin';
                        }
                        $key = 'races/' . $race->getId() . '/enrollments/' . $enrollment->getId() .
                            '/medicalExamination-' . $date->getTimestamp() . '.' . $extension;
                        Utilities::uploadOnS3($file, $key, 'private');
                        $enrollment->setMedicalExaminationPath($key);
                        $enrollment->setMedicalExaminationExpiration(isset($partialEnrollment['medicalExaminationExpiration']) ?
                            $partialEnrollment['medicalExaminationExpiration'] : null);
                        // delete file from server file system
                        unlink($partialEnrollment['medicalExaminationFilepath']);
                    }
                } else {
                    $enrollment->setMedicalExaminationPath($partialEnrollment['medicalExaminationFilepath']);
                    $enrollment->setMedicalExaminationExpiration($partialEnrollment['medicalExaminationExpiration']);
                }
            } catch (\Exception $e) {
            }

            $token = null;
            //genero il token per il caricamento del bonifico
            if ($paymentType->getName() == 'transfer') {
                $now = new DateTime();
                $token = hash('sha512', $enrollment->getId() . $now->getTimestamp());
                $enrollment->setBankTransferToken($token);
                $enrollment->setBankTransferTokenCreatedAt($now);
            }
            $em->persist($enrollment);
            $em->flush();

            //mando una mail all'atleta che si è appena iscritto
            $message = \Swift_Message::newInstance()
                ->setSubject($this->get('translator')->trans('emails.enrollment.subject') . ' ' . $event->getName())
                ->setFrom('info@picosport.net', 'Pico Sport')
                ->setTo($enrollment->getEmail())
                ->setBody(
                    $this->renderView(
                        'AppBundle::Emails/enrollmentConfirmation.html.twig', [
                            'enrollment' => $enrollment,
                            'enrollmentSummary' => $partialEnrollment,
                            'race' => $race,
                            'event' => $event,
                            'otherInfo' => $partialEnrollment['otherInfo'],
                            'token' => urlencode($token)
                        ]
                    ),
                    'text/html'
                );
            $this->get('mailer')->send($message);
            // resetto valori salvati in sessione per impedire di tornare agli step precedenti una volta conclusa la registrazione
            //$session->clear();

            return $this->render('AppBundle:Enrollment:enrollment.html.twig', array(
                'event' => $event,
                'race' => $race,
                'formStepName' => 'paymentCompleted',
                'enrollmentSummary' => $enrollment
            ));
        }
        return $this->redirect($this->generateUrl('homepage'));
    }

    /**
     * @Route("/{_locale}/{slug}/iscrizioni/{eventId}/corsa/{raceId}/carica-bonifico/{token}/", name="event_enrollment_transfer_upload_legacy")
     * @Route("/{_locale}/{slug}/iscrizioni/{eventId}/corsa/{raceId}/carica-visita-medica/{token}/", name="event_medical_examination_upload_legacy")
     * @Route("/{_locale}/{slug}/carica-bonifico/{token}/", name="event_enrollment_transfer_upload")
     * @Route("/{_locale}/{slug}/carica-visita-medica/{token}/", name="event_medical_examination_upload")
     */
    public function enrollmentActionDocumentUpload(Request $request, $token)
    {
        // TODO: remove legacy routes
        $session = $this->container->get('session');
        $routeName = $request->get('_route');
        $em = $this->getDoctrine()->getManager();
        $medicalExamination = true;

        if ($routeName == 'event_enrollment_transfer_upload') {
            $medicalExamination = false;
        }
        /** @var $enrollment \AppBundle\Entity\Enrollment */
        if ($medicalExamination) {
            $enrollment = $em->getRepository('AppBundle:Enrollment')->findOneBy(array(
                'medicalExaminationToken' => $token,
                'medicalExaminationPath' => null));
        } else {
            $enrollment = $em->getRepository('AppBundle:Enrollment')->findOneBy(array(
                'bankTransferToken' => $token,
                'bankTransferPath' => null));
        }
        if (!empty($enrollment)) {
            /** @var $race \AppBundle\Entity\Race */
            $race = $enrollment->getRace();
            /** @var $event \AppBundle\Entity\Event */
            $event = $race->getEvent();
            if (($medicalExamination && $race->getEnrollmentsCloseUploadMedicalExamination()) ||
                (!$medicalExamination && $race->getEnrollmentsCloseUploadBankTransfer())) {
                $session->getFlashBag()->add('danger', $this->get('translator')->trans('enrollment.linkExpired'));
                return $this->redirect($this->generateUrl('race_enrollments_list',
                    array(
                        'raceId' => $race->getId(),
                        'slug' => $this->get('slugify')->slugify($event->getName() . ' ' . $race->getName())
                    )
                ));
            }

            if ($medicalExamination) {
                $diff = (new DateTime())->getTimestamp() - $enrollment->getMedicalExaminationTokenCreatedAt()->getTimestamp();
            } else {
                $diff = (new DateTime())->getTimestamp() - $enrollment->getBankTransferTokenCreatedAt()->getTimestamp();
            }
            // more than 3 days
            if ($diff > 259200) {
                $session->getFlashBag()->add('danger', $this->get('translator')->trans('enrollment.linkExpired'));
                return $this->redirect($this->generateUrl('race_enrollments_list',
                    array(
                        'raceId' => $race->getId(),
                        'slug' => $this->get('slugify')->slugify($event->getName() . ' ' . $race->getName())
                    )
                ));
            }

            $formBuilder = $this->createFormBuilder()
                ->add('file', FileType::class,
                    array(
                        'label' => ($medicalExamination) ? 'enrollment.medicalExamination' : 'enrollment.bankTransfer',
                        'required' => true,
                        'constraints' => array(new NotBlank(), new \Symfony\Component\Validator\Constraints\File([
                            'maxSize' => '5M',
                            'mimeTypes' => [
                                'application/pdf',
                                'application/x-pdf',
                                'image/png',
                                'image/jpeg',
                                'image/jpeg'
                            ]]))
                    ));

            $form = $formBuilder->getForm();
            $form->handleRequest($request);
            if ($form->isValid()) {
                try {
                    $file = $form['file']->getData();
                    if ($file != null) {
                        Utilities::checkFile($file);
                        $extension = $file->guessExtension();
                        if (!$extension) {
                            $extension = 'bin';
                        }
                        $date = new DateTime();
                        if ($medicalExamination) {
                            $key = 'races/' . $race->getId() . '/enrollments/' . $enrollment->getId() .
                                '/medicalExamination-' . $date->getTimestamp() . '.' . $extension;
                            $enrollment->setMedicalExaminationPath($key);
                        } else {
                            $key = 'races/' . $race->getId() . '/enrollments/' . $enrollment->getId() .
                                '/bankTransfer-' . $date->getTimestamp() . '.' . $extension;
                            $enrollment->setBankTransferPath($key);
                        }
                        Utilities::uploadOnS3($file, $key, 'private');
                        $em->flush();
                        //mando una mail all'atleta che ha caricato il file
                        $message = \Swift_Message::newInstance()
                            ->setSubject($this->get('translator')->trans(
                                    ($medicalExamination) ? 'emails.enrollment.subjectME' :
                                        'emails.enrollment.subjectBT') . ' ' . $event->getName() . ' - ' . $race->getName())
                            ->setFrom('info@picosport.net', 'Pico Sport')
                            ->setTo($enrollment->getEmail())
                            ->setBody(
                                $this->renderView(
                                    'AppBundle::Emails/enrollmentFileUploadConfirmation.html.twig',
                                    array('enrollment' => $enrollment,
                                        'event' => $event,
                                        'race' => $race,
                                        'medicalExamination' => $medicalExamination)
                                ),
                                'text/html'
                            );
                        $this->get('mailer')->send($message);

                        //mando a crono@picosport.net una mail di notifica
                        $message = \Swift_Message::newInstance()
                            ->setSubject($this->get('translator')->trans(
                                    ($medicalExamination) ? 'emails.enrollment.subjectME' :
                                        'emails.enrollment.subjectBT') . ' ' . $event->getName() . ' - ' . $race->getName())
                            ->setFrom('info@picosport.net', 'Pico Sport')
                            ->setTo('crono@picosport.net')
                            ->setBody($this->get('translator')->trans('emails.enrollment.notifyMEOrBT',
                                array(
                                    '%name%' => $enrollment->getName(),
                                    '%surname%' => $enrollment->getSurname(),
                                    '%born%' => $enrollment->getBorn()->format('d/m/Y')
                                )
                            ));
                        $this->get('mailer')->send($message);
                        $session->getFlashBag()->add('success', $this->get('translator')->trans('enrollment.uploadOK'));
                    }
                } catch (\Exception $e) {
                    $session->getFlashBag()->add('danger', $this->get('translator')->trans('uploadFileKO'));
                }
                return $this->redirect($this->generateUrl('race_enrollments_list',
                    array(
                        'raceId' => $race->getId(),
                        'slug' => $this->get('slugify')->slugify($event->getName() . ' ' . $race->getName())
                    )
                ));
            }
            return $this->render('AppBundle:Enrollment:enrollment.html.twig', array(
                'event' => $event,
                'race' => $race,
                'form' => $form->createView(),
                'formStepName' => 'uploadFile',
                'medicalExamination' => $medicalExamination,
                'enrollmentSummary' => $enrollment
            ));
        }
        return $this->redirect($this->generateUrl('homepage'));
    }

    private function addExtraFieldsToForm($request, $race, $formBuilder)
    {
        $jsonObj = json_decode($race->getEnrollmentOptionalFields(), true);
        if ($jsonObj != null) {
            foreach ($jsonObj as $field) {
                //supporto multilingue
                $locale = 'it';
                if ($request->getLocale() == 'it') {
                    $locale = 'it';
                } elseif ($request->getLocale() == 'en') {
                    $locale = 'en';
                }
                //supporto vincoli (per ora solo NotBlank)
                $constraints = null;
                if ($field['constraints'] != 'null') {
                    $constraints = new NotBlank();
                }
                if ($field['type'] == 'choice') {
                    $choices = array();
                    foreach ($field['choices'][0]['choiches_' . $locale] as $choice) {
                        $choices[$choice] = $choice;
                    }
                    $formBuilder->add($field['name'], ChoiceType::class, array(
                        'mapped' => false,
                        'label' => $field['label_' . $locale],
                        'constraints' => $constraints,
                        'choices' => $choices,
                        'required' => false,
                        'choices_as_values' => true,
                    ));
                } elseif ($field['type'] == 'checkbox') {
                    $formBuilder->add($field['name'], CheckboxType::class, array(
                        'mapped' => false,
                        'label' => $field['label_' . $locale],
                        'constraints' => $constraints,
                        'required' => false
                    ));
                } elseif ($field['type'] == 'integer') {
                    $formBuilder->add($field['name'], IntegerType::class, array(
                        'mapped' => false,
                        'label' => $field['label_' . $locale],
                        'constraints' => $constraints,
                        'data' => $field['placeholder'],
                        'required' => false
                    ));
                } elseif ($field['type'] == 'textarea') {
                    $formBuilder->add($field['name'], TextareaType::class, array(
                        'mapped' => false,
                        'label' => $field['label_' . $locale],
                        'constraints' => $constraints,
                        'required' => false,
                        'attr' => array('placeholder' => $field['placeholder'])
                    ));
                }
            }
        }
    }

    private function getExtraFieldsFromForm($request, $race, $form)
    {
        $otherInfo = null;
        $optionalFieldsArray = array();
        if ($race->getEnrollmentOptionalFields() != null) {
            $jsonObj = json_decode($race->getEnrollmentOptionalFields(), true);
            if ($jsonObj != null) {
                $otherInfo = '<ul>';
                foreach ($jsonObj as $field) {
                    $locale = 'it';
                    if ($request->getLocale() == 'it') {
                        $locale = 'it';
                    } elseif ($request->getLocale() == 'en') {
                        $locale = 'en';
                    }
                    $optionalFieldsArray[$field['name']] = $form[$field['name']]->getData();
                    $otherInfo = $otherInfo . '<li>' . $field['label_' . $locale] . ': ' . $form[$field['name']]->getData() . '</li>';
                }
                $otherInfo = $otherInfo . '</ul>';
            }
        }
        return array($otherInfo, $optionalFieldsArray);
    }

    /**
     * @Route("/{_locale}/race/{id}/enrollmentsList/{page}/", name="race_enrollments_list_legacy")
     * @Route("/{_locale}/{slug}/elenco-iscritti/{raceId}/", name="race_enrollments_list", options={"expose"=true})
     */
    public function enrollmentsListAction(Request $request, $raceId)
    {
        $em = $this->getDoctrine()->getManager();
        /** @var $race \AppBundle\Entity\Race */
        $race = $em->getRepository('AppBundle:Race')->find($raceId);
        $event = $race->getEvent();
        if ($race == null || $race->getEnrollment() == false || $event == null) {
            return $this->redirect($this->generateUrl('homepage'));
        }
        if ($this->get('security.authorization_checker')->isGranted('ROLE_ADMIN') ||
            $race->getUsersCanManageEnrollment()->contains(
                $this->get('security.token_storage')->getToken()->getUser())) {
            $datatable = $this->get('appbundle.datatable.enrollmentManage');
            $whereCondition = "enrollment.race = $raceId";
            $template = 'AppBundle::baseDataTable.html.twig';
        }
        // se l'utente attuale non è amministratore, oppure non è un utente abilitato alla gestione delle iscrizioni
        // vede semplicemente l'elenco iscritti senza possibilità di fare modifiche
        else {
            $datatable = $this->get('appbundle.datatable.enrollment');
            $whereCondition = "enrollment.race = $raceId AND enrollment.valid = TRUE";
            $template = 'AppBundle:Enrollment:enrollmentsList.html.twig';
        }
        $datatable->buildDatatable(
            array(
                $race,
                $this->get('slugify')->slugify($race->getName())
            )
        );
        $isAjax = $request->isXmlHttpRequest();
        if ($isAjax) {
            $responseService = $this->get('sg_datatables.response');
            $responseService->setDatatable($datatable);
            $datatableQueryBuilder = $responseService->getDatatableQueryBuilder();
            $qb = $datatableQueryBuilder->getQb();
            $qb->andWhere($whereCondition);
            return $responseService->getResponse();
        }
        return $this->render($template, array(
            'titleText' => $this->get('translator')->trans('enrollment.list') . ' ' . $event->getName() . ' ' . $race->getName(),
            'event' => $event,
            'race' => $race,
            'datatable' => $datatable,
            'route' => $this->generateUrl('enrollments_list_tools', array('raceId' => $raceId)),
            'buttonText' => $this->get('translator')->trans('enrollment.tools'),
        ));
    }

    /**
     * @Route("/{_locale}/enrollment/{enrollmentId}/paymentDetails/", name="enrollment_payment_details")
     * @Security("has_role('ROLE_ADMIN')")
     */
    public function enrollmentPaymentDetailsAction(Request $request, $enrollmentId)
    {

        $em = $this->getDoctrine()->getManager();
        /** @var $enrollment \AppBundle\Entity\Enrollment */
        $enrollment = $em->getRepository('AppBundle:Enrollment')->find($enrollmentId);
        if ($enrollment == null) {
            return $this->redirect($this->generateUrl('homepage'));
        }
        return $this->render('AppBundle:Enrollment:paymentDetails.html.twig', array(
            'enrollment' => $enrollment
        ));
    }

    /**
     * @Route("/{_locale}/enrollment/{enrollmentId}/checkDocument/{documentType}/", name="enrollment_check_document")
     * @Security("has_role('ROLE_USER')")
     */
    public function enrollmentCheckDocumentAction(Request $request, $enrollmentId, $documentType)
    {

        $em = $this->getDoctrine()->getManager();
        /** @var $enrollment \AppBundle\Entity\Enrollment */
        $enrollment = $em->getRepository('AppBundle:Enrollment')->find($enrollmentId);
        if ($enrollment == null) {
            return $this->redirect($this->generateUrl('homepage'));
        }
        try {
            $race = $enrollment->getRace();
            $event = $race->getEvent();
            if ($this->get('security.authorization_checker')->isGranted('ROLE_ADMIN') ||
                $race->getUsersCanManageEnrollment()->contains(
                    $this->get('security.token_storage')->getToken()->getUser())) {
                if ($documentType == 0)
                    $path = $enrollment->getMedicalExaminationPath();
                else
                    $path = $enrollment->getBankTransferPath();
                $result = Utilities::downloadFromS3($path);
                $headers = array(
                    'Content-Type' => $result['ContentType']
                );
                return new Response($result['Body'], 200, $headers);
            }
        } catch (\Exception $e) {
        }
        $session = $this->container->get('session');
        $session->getFlashBag()->add('danger', $this->get('translator')->trans('downloadKO'));
        return $this->redirect(
            $this->generateUrl('race_enrollments_list',
                array(
                    'raceId' => $enrollment->getRace()->getId(),
                    'slug' => $this->get('slugify')->slugify($event->getName() . ' ' . $race->getName()),
                )
            )
        );
    }

    /**
     * @Route("/{_locale}/enrollment/{enrollmentId}/notifyEnrolled/{documentType}/", name="enrollment_notify_enrolled")
     * @Security("has_role('ROLE_ADMIN')")
     */
    public function enrollmentNotifyUserAction(Request $request, $enrollmentId, $documentType)
    {
        $em = $this->getDoctrine()->getManager();
        /** @var $enrollment \AppBundle\Entity\Enrollment */
        $enrollment = $em->getRepository('AppBundle:Enrollment')->find($enrollmentId);
        if ($enrollment == null) {
            return $this->redirect($this->generateUrl('homepage'));
        }
        /** @var $race \AppBundle\Entity\Race */
        $race = $enrollment->getRace();
        /** @var $event \AppBundle\Entity\Event */
        $event = $race->getEvent();
        $raceName = $event->getName() . ' - ' . $race->getName();

        //genero il token per il caricamento del documento
        $now = new DateTime();
        $token = hash('sha512', $enrollment->getId() . $now->getTimestamp());

        $form = $this->createFormBuilder()
            ->add('token', HiddenType::class,
                array(
                    'data' => $token,
                )
            )
            ->add('now', DateTimeType::class,
                array(
                    'data' => $now,
                )
            )
            ->add('destination', EmailType::class,
                array(
                    'data' => $enrollment->getEmail(),
                    'disabled' => true,
                    'label' => 'email'
                )
            )
            ->add('subject', TextType::class,
                array(
                    'data' => $this->get('translator')->trans('emails.enrollment.notValid.subject') . ' ' . $raceName,
                    'label' => 'subject',
                    'constraints' => array(
                        new NotBlank(),
                    )
                )
            )
            ->add('message', TextareaType::class,
                array(
                    'data' => $this->get('translator')->trans(($documentType == 0) ? 'emails.enrollment.notValid.textME' : 'emails.enrollment.notValid.textBT',
                        array(
                            '%name%' => $enrollment->getName(),
                            '%raceUrl%' => $this->generateUrl('event_show',
                                array(
                                    'eventId' => $event->getId(),
                                    'slug' => $this->get('cocur_slugify')->slugify($event->getName()),
                                ),
                                UrlGeneratorInterface::ABSOLUTE_URL
                            ),
                            '%raceName%' => $raceName,
                            '%documentUrl%' => $this->generateUrl(($documentType == 0) ? 'event_medical_examination_upload' : 'event_enrollment_transfer_upload',
                                array(
                                    'token' => $token,
                                    'slug' => $this->get('cocur_slugify')->slugify($raceName)
                                ),
                                UrlGeneratorInterface::ABSOLUTE_URL
                            )
                        )
                    ),
                    'constraints' => array(
                        new NotBlank(),
                    )
                )
            )
            ->add('save', SubmitType::class,
                array(
                    'label' => 'send',
                    'attr' => array('class' => 'btn btn-sm btn-success pull-right')
                )
            )
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $message = \Swift_Message::newInstance()
                ->setSubject($data['subject'])
                ->setFrom('info@picosport.net', 'Pico Sport')
                ->setTo($enrollment->getEmail())
                ->setBody($data['message'], 'text/html');
            $this->get('mailer')->send($message);
            $session = $this->container->get('session');
            $session->getFlashBag()->add('success', $this->get('translator')->trans('enrollment.notifyOK'));
            if ($documentType == 0) {
                $enrollment->setMedicalExaminationPath(null);
                $enrollment->setMedicalExaminationExpiration(null);
                $enrollment->setMedicalExaminationToken($data['token']);
                $enrollment->setMedicalExaminationTokenCreatedAt($data['now']);
            } else {
                $enrollment->setBankTransferPath(null);
                $enrollment->setBankTransferDate(null);
                $enrollment->setBankTransferAmount(null);
                $enrollment->setBankTransferToken($data['token']);
                $enrollment->setBankTransferTokenCreatedAt($data['now']);
            }
            $em->persist($enrollment);
            $em->flush();
            return $this->redirect($this->generateUrl('race_enrollments_list',
                array(
                    'raceId' => $race->getId(),
                    'slug' => $this->get('slugify')->slugify($event->getName() . ' ' . $race->getName())
                )
            ));
        }
        return $this->render('AppBundle:Enrollment:notifyEnrolled.html.twig', array(
            'form' => $form->createView()
        ));
    }

    /**
     * @Route("/{_locale}/race/{raceId}/enrollmentDelete/", name="race_enrollments_delete")
     * @Security("has_role('ROLE_ADMIN')")
     */
    public function enrollmentDeleteAction(Request $request, $raceId)
    {
        $isAjax = $request->isXmlHttpRequest();
        if ($isAjax) {
            $em = $this->getDoctrine()->getManager();
            /** @var $race \AppBundle\Entity\Race */
            $race = $em->getRepository('AppBundle:Race')->find($raceId);
            if ($race != null &&
                $race->getEnrollment() == true) {
                $choices = $request->request->get('data');
                $token = $request->request->get('token');
                if (!$this->isCsrfTokenValid('multiselect', $token)) {
                    throw new AccessDeniedException('The CSRF token is invalid . ');
                }
                $em = $this->getDoctrine()->getManager();
                $repository = $em->getRepository('AppBundle:Enrollment');
                foreach ($choices as $choice) {
                    /** @var $entity \AppBundle\Entity\Enrollment */
                    $entity = $repository->find($choice['id']);
                    if ($entity->getRace()->getId() == $race->getId()) {
                        $prefix = 'races / ' . $entity->getRace()->getId() . ' / enrollments / ' . $entity->getId() . ' / ';
                        try {
                            Utilities::deleteByPrefixFromS3($prefix);
                        } catch (\Exception $e) {
                        }
                        $em->remove($entity);
                    }
                }
                $em->flush();
                return new Response('Success', 200);
            }
        }
        return new Response('Bad Request', 400);
    }

    /**
     * @Route("/{_locale}/race/{raceId}/enrollmentsListTools/", name="enrollments_list_tools")
     * @Security("has_role('ROLE_ADMIN')")
     */
    public function enrollmentsListToolsAction(Request $request, $raceId)
    {
        $em = $this->getDoctrine()->getManager();
        /** @var $race \AppBundle\Entity\Race */
        $race = $em->getRepository('AppBundle:Race')->find($raceId);
        $event = $race->getEvent();
        if ($race == null ||
            $race->getEnrollment() == false ||
            $event == null) {
            return $this->redirect($this->generateUrl('homepage'));
        }
        return $this->render('AppBundle:Enrollment:enrollmentsListTools.html.twig', array(
            'event' => $event,
            'race' => $race,
        ));
    }

    /**
     * @Route("/{_locale}/race/{raceId}/downloadEnrollmentsList/", name="race_download_enrollments_list")
     * @Security("has_role('ROLE_ADMIN')")
     */
    public function downloadEnrollmentsListAction(Request $request, $raceId)
    {
        $em = $this->getDoctrine()->getManager();
        $translator = $this->get('translator');
        /** @var $race \AppBundle\Entity\Race */
        $race = $em->getRepository('AppBundle:Race')->find($raceId);
        if ($race == null ||
            $race->getEnrollment() == false) {
            return $this->redirect($this->generateUrl('homepage'));
        }
        try {
            // creo l'header del CSV
            // headers standard
            $headers = array(
                'chip' => $translator->trans('enrollment.chip'),
                'name' => $translator->trans('name'),
                'surname' => $translator->trans('surname'),
                'team' => $translator->trans('team'),
                'category' => $translator->trans('ranking.category'),
                'pectoral' => $translator->trans('pectoral'),
                'cardNumber' => $translator->trans('cardNumber'),
                'year' => $translator->trans('born'),
                'nationality' => $translator->trans('nationality'),
                'gender' => $translator->trans('gender'),
                'cardType' => $translator->trans('cardType'),
                '' => '',
                'medicalExamination' => $translator->trans('medicalExamination'),
                'medicalExaminationExpiration' => $translator->trans('medicalExaminationExpiration'),
                'paymentType' => $translator->trans('payment.paymentMethod'),
                'calculatedPrice' => $translator->trans('enrollment.calculatedPrice'),
                'paymentValid' => $translator->trans('enrollment.paymentValid'),
                'bankTransferDate' => $translator->trans('enrollment.bankTransferDate'),
                'bankTransferValue' => $translator->trans('enrollment.bankTransferAmount'),
                'email' => $translator->trans('email'),
                'note' => $translator->trans('enrollment.note')

            );
            // array contenente i nome degli eventuali campi opzional e servirà
            // successivamente per generare un CSV coerente.
            $extraFieldsName = array();
            if ($race->getEnrollmentOptionalFields() != null) {
                $headers = array_merge($headers, $this->getExtraFieldsHeaders($request, $race, $extraFieldsName));
            }

            $raceId = $race->getId();
            $dql = "SELECT e FROM AppBundle:Enrollment e WHERE e.race = $raceId AND e.valid = TRUE ORDER BY e.createdAt ASC, e.id ASC ";
            $query = $em->createQuery($dql);
            // modello l`array contenente le iscrizioni in base alle mie esigenze
            $enrollmentsArray = array();
            $pectoral = 1;
            /** @var $enrollment \AppBundle\Entity\Enrollment */
            foreach ($query->getResult() as $enrollment) {
                $enrollmentArray = array(
                    'chip' => '',
                    'name' => $enrollment->getName(),
                    'surname' => $enrollment->getSurname(),
                    'team' => $enrollment->getTeam(),
                    'category' => $this->computeCategory($translator, $race, $enrollment->getGender(), $enrollment->getBorn()),
                    'pectoral' => $enrollment->getPectoral() != null ? $enrollment->getPectoral() : $pectoral,
                    'cardNumber' => $enrollment->getCardNumber(),
                    'year' => $enrollment->getBorn()->format('d/m/Y'),
                    'nationality' => $enrollment->getNationality()->getCode(),
                    'gender' => $enrollment->getGender() == 'male' ? 'M' : 'F',
                    'cardType' => $enrollment->getCardType(),
                    '' => '',
                    'medicalExamination' => $enrollment->getMedicalExaminationValid() == '1' ? '1' : '0',
                    'medicalExaminationExpiration' =>
                        $enrollment->getMedicalExaminationExpiration() != null ?
                            $enrollment->getMedicalExaminationExpiration()->format('d/m/Y') : '',
                    'paymentType' => $enrollment->getPaymentMethod() != null ?
                        $translator->trans($enrollment->getPaymentMethod()->getName()) : '',
                    'calculatedPrice' => $enrollment->getCalculatedPrice(),
                    'paymentValid' => $enrollment->getPaymentValid() == '1' ? '1' : '0',
                    'bankTransferDate' =>
                        $enrollment->getBankTransferDate() != null ?
                            $enrollment->getBankTransferDate()->format('d/m/Y') : '',
                    'bankTransferValue' => str_replace(',', '.', $enrollment->getBankTransferAmount()),
                    'email' => $enrollment->getEmail(),
                    'note' => $enrollment->getNote()
                );
                //eventuali campi opzionali
                if ($enrollment->getOptionalFields() != null) {
                    $enrollmentArray = array_merge($enrollmentArray, $this->getExtraFieldsValue($enrollment, $extraFieldsName));
                }
                $enrollmentsArray[] = $enrollmentArray;
                if ($enrollment->getPectoral() == null) {
                    $pectoral++;
                }
            }

            $response = new StreamedResponse();
            $response->setCallback(
                function () use ($headers, $enrollmentsArray) {
                    $handle = fopen('php://output', 'r+');
                    //aggiungo l'header al CSV
                    fputcsv($handle, $headers, ';');
                    //creo il CSV riga per riga
                    foreach ($enrollmentsArray as $enrollment) {
                        fputcsv($handle, $enrollment, ';');
                    }
                    fclose($handle);
                }
            );
            $response->headers->set('Content-Type', 'application/force-download');
            $response->headers->set('Content-Disposition', 'attachment; filename=' . $race->getEvent()->getName() . ' - ' . $race->getName() . '.csv');
            return $response;
        } catch (\Exception $e) {
        }

        return $this->redirect($this->generateUrl('homepage'));
    }

    /**
     * @Route("/{_locale}/race/{raceId}/importEnrollmentFromCSV/", name="race_import_enrollments_from_csv")
     * @Security("has_role('ROLE_ADMIN')")
     */
    public function importEnrollmentsFromCSV(Request $request, $raceId = null)
    {
        $session = $this->container->get('session');
        $em = $this->getDoctrine()->getManager();
        if ($raceId != null) {
            /** @var $race \AppBundle\Entity\Race */
            $race = $em->getRepository('AppBundle:Race')->find($raceId);
            if ($race == null) {
                return $this->redirect($this->generateUrl('homepage'));
            }
        } else {
            return $this->redirect($this->generateUrl('homepage'));
        }
        $event = $race->getEvent();
        $form = $this->createFormBuilder()
            ->add('file', FileType::Class,
                array(
                    'label' => 'enrollment.uploadFromCSV',
                    'required' => true
                )
            )
            ->add('save', SubmitType::class,
                array(
                    'label' => 'upload',
                    'attr' => array('class' => 'btn-sm btn-success')
                )
            )
            ->getForm();
        $form->handleRequest($request);
        if ($form->isValid()) {
            try {
                $file = $form->getData()['file'];
                Utilities::checkFile($file, array('csv'));
                $count = 0;
                $header = true;
                if (($handle = fopen($file->getRealPath(), 'r')) !== FALSE) {
                    while (($row = fgetcsv($handle, 0, ';')) !== FALSE) {
                        if ($header) {
                            $header = false;
                        } else {
                            /** @var $enrollment \AppBundle\Entity\Enrollment */
                            $enrollment = new Enrollment();
                            $enrollment->setRace($race);
                            $enrollment->setName($row[0]);
                            $enrollment->setSurname($row[1]);
                            $enrollment->setTeam($row[2]);
                            $enrollment->setBorn(DateTime::createFromFormat('d/m/Y', $row[3]));
                            $enrollment->setGender($row[4] == 'M' ? 'male' : 'female');
                            $enrollment->setCardType($row[5]);
                            $enrollment->setCardNumber($row[6]);
                            $enrollment->setPhone($row[7]);
                            $enrollment->setEmail($row[8]);
                            $enrollment->setNationality($em->getRepository('AppBundle:Nationality')->findOneBy(
                                array('code' => $row[9])
                            ));
                            $em->persist($enrollment);
                            $count += 1;
                        }
                    }
                }
                fclose($handle);
                $em->flush();
                $session->getFlashBag()->add('success', $this->get('translator')->trans('enrollment.uploadCSVSuccess', array('%count%' => $count)));
                return $this->redirect(
                    $this->generateUrl('race_enrollments_list',
                        array(
                            'raceId' => $enrollment->getRace()->getId(),
                            'slug' => $this->get('slugify')->slugify($event->getName() . ' ' . $race->getName()),
                        )
                    ));
            } catch (\Exception $e) {
                $session->getFlashBag()->add('danger', $e);
            }
        }
        return $this->render('AppBundle:Enrollment:enrollmentUploadFromCSV.html.twig', array(
            'event' => $event,
            'race' => $race,
            'form' => $form->createView(),
        ));
    }

    /**
     * @Route("/{_locale}/{slug}/corsa/{raceId}/metodi-di-pagamento/", name="race_payment_methods", options={"expose"=true})
     */
    public function getRacePaymentMethods(Request $request, $raceId = null)
    {
        $em = $this->getDoctrine()->getManager();
        /** @var $race \AppBundle\Entity\Race */
        $race = $em->getRepository('AppBundle:Race')->find($raceId);

        $paymentTypes = $race->getPaymentTypesForRace();

        $encoders = [new XmlEncoder(), new JsonEncoder()];
        $normalizers = [new ObjectNormalizer()];
        $serializer = new Serializer($normalizers, $encoders);

        return new JsonResponse($serializer->serialize($paymentTypes, 'json'));
    }

    public function getRaceDiscountCode($raceId, $discountCode)
    {
        $em = $this->getDoctrine()->getManager();
        /** @var $race \AppBundle\Entity\Race */
        $race = $em->getRepository('AppBundle:Race')->find($raceId);

        if (!empty($discountCode) && !empty($race)) {
            /** @var $raceDiscountCodes \AppBundle\Entity\RaceDiscountCode */
            $raceDiscountCodes = $em->getRepository('AppBundle:RaceDiscountCode')->findBy([
                'race' => $race,
                'code' => $discountCode,
                'expired' => false
            ]);

            if (!empty($raceDiscountCodes[0])) {
                /** @var $raceDiscountCode \AppBundle\Entity\RaceDiscountCode */
                $raceDiscountCode = $raceDiscountCodes[0];
                return $raceDiscountCode;
            }
        }

        return false;
    }

    /**
     * @Route("/{_locale}/{slug}/corsa/{raceId}/verifica-codice-sconto/", name="race_discount_code_verify", options={"expose"=true})
     */
    public function verifyRaceDiscountCode(Request $request, $raceId = null)
    {
        $session = $this->container->get('session');
        $partialEnrollment = $session->get('partialEnrollment');

        $encoders = [new XmlEncoder(), new JsonEncoder()];
        $normalizers = [
            new ObjectNormalizer()
        ];
        $serializer = new Serializer($normalizers, $encoders);

        if (!isset($partialEnrollment['discountCodeVerifications'])) {
            $partialEnrollment['discountCodeVerifications'] = 0;
        }

        /* faccio qualche controllo di sicurezza per evitare attacchi
           per estrarre i possibili codici facendo molte chiamate
            - controllo che in sessione ci sia una iscrizione in corso
            - controllo che la gara passata come parametro, sia la stessa per cui ci si sta iscrivendo
            - controllo che non siano stati effettuati più di 20 richieste di verifica codice per questa iscrizione
        */
        if (!empty($partialEnrollment) && !empty($partialEnrollment['raceId']) && $partialEnrollment['raceId'] == $raceId && $partialEnrollment['discountCodeVerifications'] < 200) {
            $partialEnrollment['discountCodeVerifications']++;
            $session->set('partialEnrollment', $partialEnrollment);

            $discountCode = $request->get('discountCode');

            $raceDiscountCode = $this->getRaceDiscountCode($raceId, $discountCode);

            if (!empty($raceDiscountCode)) {
                return new JsonResponse($raceDiscountCode->jsonSerialize());
            }
        }

        return new JsonResponse($serializer->serialize(false, 'json'));
    }

    private function getExtraFieldsHeaders($request, $race, &$headersName)
    {
        $headers = array();
        $jsonObj = json_decode($race->getEnrollmentOptionalFields(), true);
        if ($jsonObj != null) {
            //supporto multilingue
            $locale = 'it';
            if ($request->getLocale() == 'it') {
                $locale = 'it';
            } elseif ($request->getLocale() == 'en') {
                $locale = 'en';
            }
            foreach ($jsonObj as $field) {
                $headers[$field['label_' . $locale]] = $field['label_' . $locale];
                $headersName[] = $field['name'];
            }
        }
        return $headers;
    }

    private function getExtraFieldsValue($enrollment, $extraFieldsName)
    {
        $optionalFields = array();
        /** @var $enrollment \AppBundle\Entity\Enrollment */
        $jsonObj = json_decode($enrollment->getOptionalFields(), true);
        if ($jsonObj != null) {
            //prendo come keys per navigare il json i nomi dei campi ordinati come gli headers del CSV
            //per aver una rappresentaizone sensata dei dati
            foreach ($extraFieldsName as $key) {
                if (array_key_exists($key, $jsonObj)) {
                    $value = $jsonObj[$key];
                    $optionalFields[$key] = $value;
                } else {
                    $optionalFields[$key] = null;
                }
            }
        }
        return $optionalFields;
    }

    public static function computeCategory($translator, $race, $gender, $born)
    {
        /** @var $race \AppBundle\Entity\Race */
        $categories = $race->getCategories();
        /** @var $category \AppBundle\Entity\Category */
        foreach ($categories as $category) {
            if ($category->getGender() === $gender) {
                if ($born >= $category->getStartDate() && $born <= $category->getEndDate())
                    return $category->getName();
            }
        }
        return $translator->trans('notAvailable');
    }
}
