<?php
    require_once 'vendor/autoload.php';
    use AmoCRM\Client\AmoCRMApiClient;
    use AmoCRM\Exceptions\AmoCRMApiException;
    use AmoCRM\Exceptions\AmoCRMApiNoContentException;
    use AmoCRM\Exceptions\AmoCRMoAuthApiException;
    use AmoCRM\Helpers\EntityTypesInterface;
    use League\OAuth2\Client\Token\AccessToken;
    use AmoCRM\Collections\TasksCollection;
    use AmoCRM\Models\TaskModel;

    define('DATA_FILE', 'data_info.json');
    define('TOKEN_FILE', 'token_info.json');

    require_once "authorize.php";
    /*
     * Перед началом работы с программой необзходимо записать свои данные интеграции в файл data_info.json.
     */
    //Проверяем наличие файлов с аутентификационными данными
    if ((!file_exists(DATA_FILE)) OR (!file_exists(TOKEN_FILE))) {
        exit('Data file or Token file not found');
    }
    //Считываем эти данные в массив
    $authData = json_decode(file_get_contents(DATA_FILE), true);
    //Валидируем данные
    if (!(
        isset($authData)
        && isset($authData['client_id'])
        && isset($authData['client_secret'])
        && isset($authData['grant_type'])
        && isset($authData['code'])
        && isset($authData['redirect_uri'])
        && isset($authData['baseDomain'])
    )){
        die("Установите данные для аутентификации в файле data_info.json");
    }
    //Считываем токены в массив.
    $accessToken = json_decode(file_get_contents(TOKEN_FILE), true);
    if (!(
        isset($accessToken)
        && isset($accessToken['access_token'])
        && isset($accessToken['refresh_token'])
        && isset($accessToken['expires_in'])
    )){
        $accessToken = getToken($authData);
        //$accessToken = json_decode(file_get_contents(TOKEN_FILE), true);
    }
    $apiClient = new AmoCRMApiClient($authData['client_id'], $authData['client_secret'], $authData['redirect_uri']);
    $apiClient->setAccountBaseDomain($authData['baseDomain']);

    //устанавливаем токены нашему соединению
    $apiClient->setAccessToken(new AccessToken([
    'access_token' => $accessToken['access_token'],
    'refresh_token' => $accessToken['refresh_token'],
    'expires' => $accessToken['expires_in'],
    'baseDomain' => $authData['baseDomain'],
]));;

    //Получаем список сделок в виде массива
    try {
        $leadsArr=$apiClient->leads()->get()->all();
    } catch (AmoCRMoAuthApiException $e) {
        //Если произошла ошибка аутентификации - возможно токен устарел, пробуем получить новый
        //$accessToken = getToken($authData);
        die("Неверные данные для аутентификации. Попробуйте изменить их и перезагрузиться. $e");
    } catch (AmoCRMApiException $e) {
        die($e);
    }
    //Получаем список задач в виде массива
    try {
        $tasksArr= $apiClient->tasks()->get()->all();
    } catch (AmoCRMApiNoContentException $e) {

    } catch (AmoCRMApiException $e) {
        die($e);
    }
    //Переделываем массив сделок так, чтобы индексом являлся id сделки
    if(isset($leadsArr)){
        foreach ($leadsArr as $key=>$lead){
            $leadsArr[$lead->getId()]=$lead;
            unset($leadsArr[$key]);
        }
    }
    //Переделываем массив задач так, чтобы индексом являлся id сущности, на которую ссылается задача
    if(isset($tasksArr)){
        foreach ($tasksArr as $key=>$task){
            $tasksArr[$task->getEntityId()]=$task;
            unset($tasksArr[$key]);
        }
        $leadsArr=array_diff_key($leadsArr, $tasksArr);//Удаляем из массива сделок все сделки, id которых совпадает с ententityId задачи
    }
    //Добавляем задачи в коллекцию
    $tasksCollection=new TasksCollection();
    foreach ($leadsArr as $lead){
        $task = new TaskModel();;
        $task->setTaskTypeId(TaskModel::TASK_TYPE_ID_CALL)
            ->setText('Сделка без задачи')
            ->setCompleteTill(mktime(10, 0, 0, 8, 10, 2020))
            ->setEntityType(EntityTypesInterface::LEADS)
            ->setEntityId($lead->getId())
            ->setDuration(30 * 60 * 60) //30 минут
            ->setResponsibleUserId($lead->getResponsibleUserId());
            $tasksCollection->add($task);
    }
    //Если коллекция задач не пуста, добавляем ее в список задач
    if(!$tasksCollection->isEmpty()) {
        $tasksCollection = $apiClient->tasks()->add($tasksCollection);
        echo "Задачи добавлены";
    }
    else {
        echo "У всех сделок есть задачи";
    }
