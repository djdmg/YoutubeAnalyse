<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email as EmailConstraint;
use Symfony\Component\Validator\Constraints\Range;

class ProfileEmailSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('notifEmail', EmailType::class, [
                'label'       => 'Adresse email de réception',
                'required'    => false,
                'constraints' => [new EmailConstraint()],
                'attr'        => ['placeholder' => 'vous@exemple.com'],
            ])
            ->add('smtpHost', TextType::class, [
                'label'    => 'Hôte SMTP',
                'required' => false,
                'attr'     => ['placeholder' => 'mail.exemple.com'],
            ])
            ->add('smtpPort', IntegerType::class, [
                'label'       => 'Port SMTP',
                'required'    => false,
                'data'        => $options['data']->getSmtpPort() ?? 587,
                'constraints' => [new Range(['min' => 1, 'max' => 65535])],
            ])
            ->add('smtpEncryption', ChoiceType::class, [
                'label'   => 'Chiffrement',
                'choices' => [
                    'STARTTLS (Port 587)' => 'starttls',
                    'SSL/TLS (Port 465)'  => 'ssl',
                    'Aucun'               => 'none',
                ],
                'required' => false,
            ])
            ->add('smtpUser', TextType::class, [
                'label'    => 'Utilisateur SMTP',
                'required' => false,
                'attr'     => ['placeholder' => 'user@exemple.com'],
            ])
            ->add('smtpPassword', PasswordType::class, [
                'label'    => 'Mot de passe SMTP',
                'required' => false,
                'mapped'   => false, // handled manually to avoid overwriting with empty
                'attr'     => ['placeholder' => $options['has_password'] ? '••••••••' : ''],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'   => User::class,
            'has_password' => false,
        ]);
    }
}
