<?php

namespace App\Form;

use App\Service\CsvReader;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** Choix du fichier CSV à importer. */
class ImportUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('file', FileType::class, [
            'label' => 'Fichier CSV',
            'help' => 'Fichier .csv ou .txt de 2 Mo maximum et '.CsvReader::MAX_ROWS.' lignes au plus, première ligne = noms des colonnes. Séparateur et encodage sont reconnus automatiquement.',
            'attr' => ['accept' => '.csv,.txt,text/csv,text/plain'],
            'constraints' => [
                new Assert\NotNull(message: 'Choisissez un fichier.'),
                new Assert\File(
                    maxSize: '2M',
                    // Les CSV sont souvent détectés comme « text/plain » (ou « application/vnd.ms-excel » depuis Windows) : on accepte ces types.
                    extensions: [
                        'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'text/x-csv', 'text/x-comma-separated-values'],
                        'txt' => ['text/plain', 'text/csv'],
                    ],
                    maxSizeMessage: 'Le fichier dépasse 2 Mo.',
                    extensionsMessage: 'Le fichier doit être au format .csv ou .txt.',
                ),
            ],
        ]);
    }
}
