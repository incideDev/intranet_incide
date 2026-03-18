<?php

namespace Services;

/**
 * Costanti per estrazioni (centralizzate)
 * Fonte unica di verità per i label dei type_code delle estrazioni.
 */
class ExtractionConstants
{
    private const EXTRACTION_TYPE_LABELS = [
        'data_scadenza_gara_appalto' => 'Data di scadenza della gara',
        'data_uscita_gara_appalto' => 'Data di pubblicazione della gara',
        'stazione_appaltante' => 'Stazione appaltante',
        'tipologia_di_gara' => 'Tipologia di gara',
        'tipologia_di_appalto' => 'Tipologia di appalto',
        'luogo_provincia_appalto' => 'Luogo o provincia dell\'appalto',
        'sopralluogo_obbligatorio' => 'Sopralluogo obbligatorio',
        'settore_industriale_gara_appalto' => 'Settore della gara',
        'oggetto_appalto' => 'Oggetto dell\'appalto',
        'requisiti_tecnico_professionali' => 'Requisiti tecnico-professionali',
        'link_portale_stazione_appaltante' => 'Link al portale della stazione appaltante',
        'importi_opere_per_categoria_id_opere' => 'Importi opere per categoria',
        'importi_corrispettivi_categoria_id_opere' => 'Importi corrispettivi per categoria',
        'importi_requisiti_tecnici_categoria_id_opere' => 'Importi requisiti tecnici per categoria',
        'documentazione_richiesta_tecnica' => 'Documentazione tecnica richiesta',
        'fatturato_globale_n_minimo_anni' => 'Fatturato globale minimo (anni)',
        'requisiti_di_capacita_economica_finanziaria' => 'Requisiti di capacità economico-finanziaria',
        'requisiti_idoneita_professionale_gruppo_lavoro' => 'Requisiti di idoneità professionale del gruppo di lavoro',
        'settore_gara' => 'Settore della gara (classificazione)',
        'criteri_valutazione_offerta_tecnica' => 'Criteri di valutazione dell\'offerta tecnica',
        'documenti_di_gara' => 'Documenti di gara',
    ];

    public static function getDisplayName(string $type): string
    {
        return self::EXTRACTION_TYPE_LABELS[$type] ?? ucwords(str_replace('_', ' ', $type));
    }

    public static function getLabel(string $type): string
    {
        return self::getDisplayName($type);
    }
}

