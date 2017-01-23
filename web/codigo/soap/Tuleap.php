<?php

/**
 * Created by PhpStorm.
 * User: saulocorreia
 * Date: 1/11/2017
 * Time: 4:58 PM
 */
class Tuleap
{
    const HOST = 'http://gestaoaplicacoes.mec.gov.br';
    const HOST_LOGIN = self::HOST . '/soap/?wsdl';
    const HOST_PROJECT = self::HOST . '/soap/project/?wsdl';
    const HOST_TRACKER = self::HOST . '/plugins/tracker/soap/?wsdl';

    private $client_login;
    private $client_project;
    private $client_tracker;

    private $session_hash;

    private $dados;

    private static function inserirCrossReferences ($artefato)
    {
        $querysThread = [];

        foreach ($artefato as $key3 => $value3)
        {
            $falhou = false;
            foreach ($value3->cross_references as $key4 => $value4)
            {
                $existe = UtilDAO::getResult(Querys::SELECT_CROSS_REFERENCE_BY_ALL, $value3->artifact_id, $value4->ref, $value4->url);
                if (count($existe) == 0)
                {
                    $falhou = true;
                    break;
                }
            }
            if ($falhou)
            {
                $querysThread[] = UtilDAO::MontarQuery(Querys::DELETE_CROSS_REFERENCE, $value3->artifact_id);

                foreach ($value3->cross_references as $key4 => $value4)
                    $querysThread[] = UtilDAO::MontarQuery(Querys::INSERT_CROSS_REFERENCE, $value3->artifact_id, $value4->ref, $value4->url);

            }
        }

        if (count($querysThread) > 0)
        {
            error_log('Inserindo CrossReferences');

            UtilDAO::executeArrayQuery($querysThread);

            error_log('Inserindo CrossReferences finalizado');
        }
    }

    /**
     * @param $artifacts
     * @param $value2
     */
    private static function inserirArtefato ($artifacts, $value2)
    {
        $querysThread = [];

        foreach ($artifacts as $key3 => $value3)
        {
            $existe = UtilDAO::getResult(Querys::SELECT_ARTIFACT_BY_ID, $value3->artifact_id);
            if (count($existe) > 0)
            {
                $existe = $existe[0];
                if ($existe->tracker_id != $value3->tracker_id
                    || $existe->submitted_by != $value3->submitted_by
                    || $existe->submitted_on != $value3->submitted_on
                    || $existe->last_update_date != $value3->last_update_date
                    || $existe->artifact_id != $value3->artifact_id
                    || $existe->group_id != $value2->group_id
                )
                {
                    $querysThread[] = UtilDAO::MontarQuery(Querys::UPDATE_ARTIFACT,
                        $value3->tracker_id
                        , $value2->group_id
                        , $value3->submitted_by
                        , $value3->submitted_on
                        , $value3->last_update_date
                        , $value3->artifact_id
                    );
                }
            }
            else
            {
                $querysThread[] = UtilDAO::MontarQuery(Querys::INSERT_ARTIFACT,
                    $value3->artifact_id
                    , $value3->tracker_id
                    , $value2->group_id
                    , $value3->submitted_by
                    , $value3->submitted_on
                    , $value3->last_update_date
                );
            }
        }

        UtilDAO::executeArrayQuery($querysThread);
    }

    public function buscaDadosProjeto ()
    {
        $this->init();

        register_shutdown_function('generateCallTrace', new Exception());

        set_time_limit(600);
        error_log('Buscando Projetos');
        $this->dados = $this->client_login->getMyProjects($this->session_hash);
        error_log('Buscando Projetos finalizados');
    }

    public function buscaDadosTracker ()
    {
        $this->buscaDadosProjeto();

        error_log('Buscando Trackers');
        foreach ($this->dados as $key => $value)
        {
            $this->dados[$key]->tracker = $this->client_tracker->getTrackerList($this->session_hash, $value->group_id);
        }
        error_log('Buscando Trackers finalizados');
    }

    public function buscaDadosArtifacts ()
    {
        $this->buscaDadosTracker();

        error_log('Buscando Artifacts');
        foreach ($this->dados as $key => $value)
        {
            foreach ($this->dados[$key]->tracker as $key2 => $value2)
            {
                $this->dados[$key]->tracker[$key2]->artifacts = $this->client_tracker->getArtifacts($this->session_hash, $value->group_id, $value2->tracker_id)->artifacts;
            }
        }
        error_log('Buscando Trackers finalizado');
    }

    public function inserirDadosProjeto ()
    {
        $this->buscaDadosProjeto();

        error_log('Inserindo Projetos');
        foreach ($this->dados as $key => $value)
        {
            $existe = UtilDAO::getResult(Querys::SELECT_PROJETO_BY_ID, $value->group_id);
            if (count($existe) > 0)
            {
                UtilDAO::executeQueryParam(Querys::UPDATE_PROJETO,
                    $value->group_name
                    , $value->unix_group_name
                    , $value->description
                    , $value->group_id
                );
            }
            else
            {
                UtilDAO::executeQueryParam(Querys::INSERT_PROJETO,
                    $value->group_id
                    , $value->group_name
                    , $value->unix_group_name
                    , $value->description
                );
            }
        }
        error_log('Inserindo Projetos finalizados');

        return Util::printInTree($this->dados);
    }

    public function inserirDadosTracker ()
    {
        $this->buscaDadosTracker();

        error_log('Inserindo Tracker');
        foreach ($this->dados as $key => $value)
        {
            foreach ($this->dados[$key]->tracker as $key2 => $value2)
            {
                $existe = UtilDAO::getResult(Querys::SELECT_TRACKER_BY_ID, $value2->tracker_id);
                if (count($existe) > 0)
                {
                    UtilDAO::executeQueryParam(Querys::UPDATE_TRACKER,
                        $value2->group_id
                        , $value2->name
                        , $value2->description
                        , $value2->item_name
                        , $value2->tracker_id
                    );
                }
                else
                {
                    UtilDAO::executeQueryParam(Querys::INSERT_TRACKER,
                        $value2->tracker_id
                        , $value2->group_id
                        , $value2->name
                        , $value2->description
                        , $value2->item_name
                    );
                }
            }
        }
        error_log('Inserindo Tracker finalizado');

        return Util::printInTree($this->dados);
    }

    public function inserirDadosArtifacts ()
    {
        $this->trataTudo();

        error_log('Inserindo Artefatos');

        foreach ($this->dados as $key => $value)
        {
            foreach ($this->dados[$key]->tracker as $key2 => $value2)
            {
                // ARTIFACTS
                self::inserirArtefato($value2->artifacts, $value2);

                // CROSS REFERENCES
                self::inserirCrossReferences($value2->artifacts);
            }
        }

        error_log('Inserindo Artefatos finalizado');
        return Util::printInTree($this->dados);
    }

    function trataTudo ()
    {
        $this->buscaDadosArtifacts();

        try
        {
            foreach ($this->dados as $key => &$value)
            {
                foreach ($value->tracker as $key2 => &$value2)
                {
                    foreach ($value2->artifacts as $key3 => &$value3)
                    {
                        foreach ($value3->value as $key4 => &$value4)
                        {
                            if (property_exists($value4->field_value, 'value'))
                            {
                                if (Util::endsWith($value4->field_name, 'date'))
                                {
                                    $value4->field_value = Util::trataData($value4->field_value->value);
                                }
                                else
                                {
                                    $value4->field_value = $value4->field_value->value;
                                }
                            }
                            elseif (property_exists($value4->field_value, 'bind_value'))
                            {
                                $value4->field_value = $value4->field_value->bind_value;
                            }
                        }
                    }
                }
            }
        } catch (Exception $e)
        {

        }

        return $this->dados;
    }

    private
    function init ()
    {
        if (isset($this->session_hash))
        {
            return;
        }

        error_log('Inicializando leitura dos WS');
        $SOAP_OPTION = [
            'cache_wsdl'     => WSDL_CACHE_NONE,
            'exceptions'     => 1,
            'trace'          => true,
            'encoding'       => 'UTF-8',
            'stream_context' => stream_context_create([
                'ssl' => [// set some SSL/TLS specific options
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true
                ]
            ])
        ];

        $this->client_login = new SoapClient(self::HOST_LOGIN, $SOAP_OPTION);

        $this->session_hash = $this->client_login->login($_SESSION['TULEAP_USER'], $_SESSION['TULEAP_PASS'])->session_hash;

        $this->client_project = new SoapClient(self::HOST_PROJECT, $SOAP_OPTION);
        $this->client_tracker = new SoapClient(self::HOST_TRACKER, $SOAP_OPTION);
        error_log('WS conectados');
    }
}