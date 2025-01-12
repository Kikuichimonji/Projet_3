<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserFormType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserController extends AbstractController
{
    /**
     * Show the user profile page
     *
     * @return Response
     * 
     */
    public function index($id = null, ManagerRegistry $doctrine): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $user = $id == null ? $this->getUser() : $doctrine->getRepository(User::class)->findOneBy(['id' => $id]);
        return $this->render('user/index.html.twig', [
            'user' => $user ?? $this->getUser(),
        ]);
    }

    /**
     * Validate the user's info form then update them
     *
     * @param Request $request
     * @param ManagerRegistry $doctrine
     * @param UserPasswordHasherInterface $passwordHasher
     * 
     * @return Response
     * 
     */
    public function update(Request $request,ManagerRegistry $doctrine,UserPasswordHasherInterface $passwordHasher): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $manager = $doctrine->getManager();
        $userSession = $this->getUser();
        $filesystem = new Filesystem();
        $userRepository = $doctrine->getRepository(User::class);
        $user = $userRepository->findOneBy(["email" => $request->get('mailOri')]);
        $userPath = 'assets/img/'.$user->getEmail();

        if($user->getId() != $userSession->getId()){ //If the user in session is different than the profile they are looking, they need to be admin to update
            $this->denyAccessUnlessGranted('ROLE_MANAGER');
        }
        $session = $request->getSession();

        if(!$filesystem->exists($userPath)){ //If the user do not have any file folder we create a new one
            $filesystem->mkdir($userPath);
        }
        
        $form = $this->createForm(UserFormType::class);
        //$allForm =array_merge($request->request->all(),$request->files->all());

        $form->submit($request->request->all());

        if (!$form->isValid()) { //validate form info in UserFormType
            $errors = $form->getErrors(true); // Array of Error
            return $this->render('user/index.html.twig', [
                'user' => $user,
                'errors' => $errors,
            ]);
        }

        if(!empty($request->get('lastName'))){
            $user->getLastName() == $request->get('lastName') ? null : $user->setLastName($request->get('lastName'));
        }
        if(!empty($request->get('firstName'))){
            $user->getFirstName() == $request->get('firstName') ? null : $user->setFirstName($request->get('firstName'));
        }
        if(!empty($request->get('password'))){
            if($user->getEmail() == "demo@demo.fr"){
                $this->addFlash('error', "Vous n'avez pas la permission de changer le mot de passe du compte démo");
                return $this->render('user/index.html.twig', [
                    'user' => $user,
                ]);
            }
            $hashedPassword = $passwordHasher->hashPassword( //Hashing the new passowrd
                $user,
                $request->get('password')
            );
            $user->setPassword($hashedPassword);
        }
        
        $user->setPhone($request->get('phone')); //can be empty

        if(!empty($request->files->get("form_file"))){ //Can't make the symfony validation work for file, so i manually check the infos

            $allowedMimes = [
                'image/png',
                'image/jpg',
                'image/jpeg',
                'image/gif',
                'image/svg+xml',
                'image/webp',
            ];
            $maxFileSize = 512000;
            $maxWidth = 500;
            $maxHeight = 500;
            
            $file = $request->files->get("form_file");
            
            if(!in_array($file->getClientMimeType(),$allowedMimes)){ //Check if the file mime is allowed
                $mimeError = "Le type de fichier n'est pas accepté (".$file->getClientMimeType()."), accepté : ";
                foreach ($allowedMimes as $mime) {
                    $mimeError.=$mime.", ";
                }
                $this->addFlash('error', $mimeError);
            }
            if($file->getSize() >= $maxFileSize ){ //Check if the file size is too big
                $this->addFlash('error', "Le fichier est trop volumineux (".$file->getSize()."), 500ko accepté");
            }
            
            if(!empty($session->getFlashBag()->peekAll())){
                return $this->render('user/index.html.twig', [
                    'user' => $user,
                ]);
            }

            if(getimagesize($file)[0] > $maxWidth || getimagesize($file)[1] > $maxHeight){ //Check if the image size is too big
                $this->addFlash('error', "Les dimentions du fichier sont trop grandes (".getimagesize($file)[0]."px x ".getimagesize($file)[1]."px), 500 x 500 Max");
                return $this->render('user/index.html.twig', [
                    'user' => $user,
                ]);
            }

            if($file->move($userPath,"profileImage.".$file->getClientOriginalExtension())){ //If we successfully move the file to the folder we assign it in the user
                $user->setPicture($user->getEmail()."/profileImage.".$file->getClientOriginalExtension());
            }
        }

        if(!empty($request->get('email')) && $request->get('email') != $user->getEmail() ){ //check if the mail change, if that's the case we change the folder name
            if($user->getEmail() == "demo@demo.fr"){
                $this->addFlash('error', "Vous n'avez pas la permission de changer le mail du compte démo");
                return $this->render('user/index.html.twig', [
                    'user' => $user,
                ]);
            }
            $userMail = $userRepository->findOneBy(["email" => $request->get('email')]);
            if($userMail){ //If someone already use the mail
                $this->addFlash('error', "Cet email est déjà utilisé");
                return $this->render('user/index.html.twig', [
                    'user' => $user,
                ]);
            }
            $oldPath = 'assets/img/'.$user->getEmail();
            $user->setEmail($request->get('email'));
            $userPath = 'assets/img/'.$user->getEmail();
            
            if(!$filesystem->exists($userPath)){
                $filesystem->rename($oldPath, $userPath);
            }
            $explodedPic = explode('/',$user->getPicture());
            $user->setPicture($user->getEmail()."/".$explodedPic[1]);
        }

        if(!empty($request->get('roles'))){
            $this->denyAccessUnlessGranted('ROLE_MANAGER');
            $roles = ["ROLE_".strtoupper($request->request->get("roles"))];

            if($roles == ["ROLE_ADMIN"]){
                $this->denyAccessUnlessGranted('ROLE_ADMIN');
            }
            if($roles == ["ROLE_SUPER_ADMIN"]){
                $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');
            }
            $user->setRoles($roles);
        }

        $manager->persist($user);
        $manager->flush();
        
        $this->addFlash('success', "Les informations ont bien été modifiées");
        return $this->render('user/index.html.twig', [
            'user' => $user,
        ]);
    }
}
