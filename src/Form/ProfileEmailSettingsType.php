<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
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
            ->add('telegramChatId', TextType::class, [
                'label'    => 'Telegram Chat ID',
                'required' => false,
                'attr'     => ['placeholder' => 'ex: 123456789 — obtenez-le via @userinfobot'],
            ])
            ->add('estimatedRpm', NumberType::class, [
                'label'       => 'RPM estimé (€/1 000 vues)',
                'required'    => false,
                'html5'       => true,
                'scale'       => 2,
                'constraints' => [new Range(['min' => 0, 'max' => 1000])],
                'attr'        => ['placeholder' => '2.00', 'step' => '0.01', 'min' => '0'],
                'help'        => 'Revenu estimé pour 1 000 vues. Valeur par défaut : 2,00 €.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
