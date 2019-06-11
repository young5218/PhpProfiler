<?php
//初始化
$curl = curl_init();
//设置抓取的url

curl_setopt($curl, CURLOPT_URL, 'http://172.20.12.13:4575/probe/command');
//设置头文件的信息作为数据流输出
curl_setopt($curl, CURLOPT_HEADER, 1);
//设置获取的信息以文件流的形式返回，而不是直接输出。
curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
//设置post方式提交
curl_setopt($curl, CURLOPT_POST, 1);
$json = '[{"content":[],"header":{"pt":"command","serverName":"testTomcatWin","srid":"691951bb6ad5036b2d042349d5693251","tid":"EYSJYfTGVj4363825325","version":"gaochy8"}}]';
curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
//执行命令
$data = curl_exec($curl);
//关闭URL请求
curl_close($curl);
//显示获得的数据
print_r($data);