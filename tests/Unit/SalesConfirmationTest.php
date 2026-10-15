<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class SalesConfirmationTest extends TestCase
{
    public function test_cancelled_delete_confirmation_prevents_submission_without_busy_state(): void
    {
        $code = <<<'JS'
const fs = require('fs');
const vm = require('vm');
const handlers = {};
let answer = false, prompts = 0, saved = 0;
const button = {type:'submit', disabled:false, dataset:{}, classList:{add(){}}};
const form = {dataset:{confirm:'Eliminar servicio?'},method:'post',checkValidity:()=>true,matches:()=>false,querySelectorAll:()=>[button],setAttribute(){}};
const document = {body:{style:{},dataset:{timezone:'UTC'}},hidden:false,querySelector:()=>null,querySelectorAll:()=>[],getElementById:()=>null,addEventListener:(type,callback)=>{(handlers[type]??=[]).push(callback)}};
const context = {document,window:{innerWidth:1200,addEventListener(){},confirm(){prompts++;return answer;}},location:{href:'http://127.0.0.1/leads/1',pathname:'/leads/1',search:''},sessionStorage:{getItem:()=>null,setItem(){saved++;},removeItem(){}},URL,URLSearchParams,Intl,Set,setTimeout(){},clearTimeout(){},setInterval(){},history:{replaceState(){}}};
vm.runInNewContext(fs.readFileSync(process.argv[1],'utf8'),context);
const cancelled = {target:form,defaultPrevented:false,preventDefault(){this.defaultPrevented=true;}};
handlers.submit.forEach(callback=>callback(cancelled));
const cancelState = {prevented:cancelled.defaultPrevented,disabled:button.disabled,saved,prompts};
answer = true;
const accepted = {target:form,defaultPrevented:false,preventDefault(){this.defaultPrevented=true;}};
handlers.submit.forEach(callback=>callback(accepted));
console.log(JSON.stringify({cancelState,accepted:{prevented:accepted.defaultPrevented,disabled:button.disabled,saved,prompts}}));
JS;
        $process = new Process(['node', '-e', $code, dirname(__DIR__, 2).'/public/sales.js']);
        $process->mustRun();
        $states = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['prevented' => true, 'disabled' => false, 'saved' => 0, 'prompts' => 1], $states['cancelState']);
        $this->assertSame(['prevented' => false, 'disabled' => true, 'saved' => 1, 'prompts' => 2], $states['accepted']);
    }
}
