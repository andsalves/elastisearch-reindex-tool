<?php

$config = include_once("config.php");
include_once("Requester.php");
$indicesArray = json_decode(file_get_contents("indices.json"), true);
$indexSettings = json_decode(file_get_contents("default-index-settings.json"), true);

$requester = new Requester();

$url = $config['elasticsearch_host'];

$newIndexNameFn = function ($oldOne) {
    if (preg_match('/_v(?P<version>[2-9]{1,2})$/', $oldOne, $matches)) {
        return str_replace('_v' . $matches['version'], '_v' . ($matches['version'] + 1), $oldOne);
    }

    return "{$oldOne}_v2";
};

foreach ($indicesArray as $indexOptions) {
    if (isset($indexOptions['ok']) && $indexOptions['ok'] == true) {
        continue;
    }

    $index = $indexOptions['index'];
    $newIndex = $newIndexNameFn($index);
    print sprintf("\n Reindexando index '$index' para novo index chamado '$newIndex'. \n");

    $requestIndexMap = $result = $requester->request("$url/$index", [], 'GET');
    if (isset($requestIndexMap['content'][$index]['mappings'])) {
        $newIndexConfig = array(
            'settings' => $indexSettings,
            'mappings' => $requestIndexMap['content'][$index]['mappings']
        );
        $aliases = array(
            $index => $newIndex
        );
        if (isset($requestIndexMap['content'][$index]['aliases'])) {
            foreach (array_keys($requestIndexMap['content'][$index]['aliases']) as $alias) {
                $aliases[$alias] = $newIndex;
            }
        }

        print sprintf("\n Criando novo index chamado '$newIndex'... ");

        $result = $requester->request("$url/$newIndex", $newIndexConfig, 'PUT');

        if (isset($result['content']['acknowledged']) && $result['content']['acknowledged']) {
            print sprintf("\n Aguardando para garantir ack de criação de index... ");
            sleep(2);
            print sprintf("\n Index criado com sucesso. ");
            print sprintf("\n Verificando existência... ");
            $result = $requester->request("$url/$newIndex", [], 'GET');

            if (isset($result['content'][$newIndex])) {
                print sprintf("\n OK. ");
                print sprintf("\n Fazendo processo de _reindex... ");

                $result = $requester->request("$url/_reindex", array(
                    'source' => array(
                        'index' => $index
                    ),
                    'dest' => array(
                        'index' => $newIndex
                    )
                ), 'POST');
                print sprintf("\n Aguardando para garantir indexação... ");
                sleep(10);

                if (isset($result['content']['total']) && isset($result['content']['created'])) {
                    print sprintf(
                        "\n %s de %s documentos criados para novo index... ",
                        $result['content']['created'], $result['content']['total']
                    );

                    if ($result['content']['created'] == $result['content']['total']) {
                        print sprintf("\n Checando quantidade de documentos em cada index... ");

                        $result = $requester->request("$url/$index/_count", [], 'GET');

                        if (isset($result['content']['count'])) {
                            $qtd1 = $result['content']['count'];
                            $result = $requester->request("$url/$newIndex/_count", [], 'GET');

                            if (isset($result['content']['count'])) {
                                $qtd2 = $result['content']['count'];

                                if ($qtd1 == $qtd2) {
                                    print sprintf("\n A quantidade de docs em '$index' e '$newIndex' "
                                        . "é igual ($qtd1 docs)... ");

                                    print sprintf("\n Deletando index antigo ($index)... ");

                                    $result = $requester->request("$url/$index", [], 'DELETE');

                                    if (
                                        isset($result['content']['acknowledged']) && $result['content']['acknowledged']
                                    ) {
                                        print sprintf("\n Index '$index' deletado com sucesso... ");
                                        print sprintf("\n Aguardando para garantir ack de DELETE... ");
                                        sleep(3);
                                        print sprintf("\n Criando alias chamado '$index' para '$newIndex' ... ");

                                        $aliasActions = [];

                                        foreach ($aliases as $alias => $indexAlias) {
                                            $aliasActions[] = array(
                                                'add' => array(
                                                    'index' => $indexAlias,
                                                    'alias' => $alias
                                                )
                                            );
                                        }

                                        $result = $requester->request("$url/_aliases", array(
                                            'actions' => $aliasActions
                                        ), 'POST');

                                        if (
                                            isset($result['content']['acknowledged'])
                                            && $result['content']['acknowledged']
                                        ) {
                                            print sprintf("\n Alias criado com sucesso ... ");
                                            print sprintf("\n Fim da reindexação de '$index'. \n\n");
                                        } else {
                                            error_log("\nErro ao tentar criar alias! ");
                                        }
                                    } else {
                                        error_log("\nErro ao deletar index '$index'! ");
                                    }
                                } else {
                                    error_log("\nA quantidade de docs em '$index' e '$newIndex' "
                                        . "diverge ($qtd1 vs $qtd2)! ");
                                }
                            } else {
                                error_log("\nErro ao obter quantitativo de docs em '$newIndex'. ");
                            }
                        } else {
                            error_log("\nErro ao obter quantitativo de docs em '$index'. ");
                        }
                    } else {
                        error_log("\nO número de documento criados em '$newIndex' é diferente do total. ");
                    }
                }
            } else {
                error_log("\nFalha ao verificar existência de index criado chamado '$newIndex'. ");
            }
        } else {
            error_log("\nFalha ao criar index chamado '$newIndex'. ");
        }
    } else {
        error_log("\nNão foi possível obter mapping atual para index '$index'. ");
    }
}
