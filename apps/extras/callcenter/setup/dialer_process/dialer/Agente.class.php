<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Elastix version 1.2-2                                               |
  | http://www.elastix.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2006 Palosanto Solutions S. A.                         |
  +----------------------------------------------------------------------+
  | Cdla. Nueva Kennedy Calle E 222 y 9na. Este                          |
  | Telfs. 2283-268, 2294-440, 2284-356                                  |
  | Guayaquil - Ecuador                                                  |
  | http://www.palosanto.com                                             |
  +----------------------------------------------------------------------+
  | The contents of this file are subject to the General Public License  |
  | (GPL) Version 2 (the "License"); you may not use this file except in |
  | compliance with the License. You may obtain a copy of the License at |
  | http://www.opensource.org/licenses/gpl-license.php                   |
  |                                                                      |
  | Software distributed under the License is distributed on an "AS IS"  |
  | basis, WITHOUT WARRANTY OF ANY KIND, either express or implied. See  |
  | the License for the specific language governing rights and           |
  | limitations under the License.                                       |
  +----------------------------------------------------------------------+
  | The Original Code is: Elastix Open Source.                           |
  | The Initial Developer of the Original Code is PaloSanto Solutions    |
  +----------------------------------------------------------------------+
  $Id: DialerProcess.class.php,v 1.48 2009/03/26 13:46:58 alex Exp $ */

define('AST_DEVICE_NOTINQUEUE', -1);
define('AST_DEVICE_UNKNOWN',    0);
define('AST_DEVICE_NOT_INUSE',  1);
define('AST_DEVICE_INUSE',      2);
define('AST_DEVICE_BUSY',       3);
define('AST_DEVICE_INVALID',    4);
define('AST_DEVICE_UNAVAILABLE',5);
define('AST_DEVICE_RINGING',    6);
define('AST_DEVICE_RINGINUSE',  7);
define('AST_DEVICE_ONHOLD',     8);

class Agente
{
    private $_listaAgentes;
    
    /* Referencia a la llamada atendida por el agente, o NULL si no atiende. 
     * Para entrar y salir de hold se requiere [incoming/outgoing, canal cliente, 
     * id call, id current call ]*/
    private $_llamada = NULL;

    // ID en la base de datos del agente
    private $_id_agent = NULL;
    private $_name = NULL;
    private $_number = NULL;
    private $_estatus = NULL;
    private $_type = NULL;

    /*  Estado de la consola. Los valores posibles son 
        logged-out  No hay agente logoneado
        logging     Agente intenta autenticarse con la llamada
        logged-in   Agente fue autenticado y está logoneado en consola
     */
    private $_estado_consola = 'logged-out';
    
    /* El número de la extensión interna que se logonea al agente. En estado
       logout la extensión es NULL. Se supone que el canal debería contener 
       como prefijo a esta cadena. Formato esperado SIP/1064.
     */
    private $_extension = NULL;
    
    /* El ID de la sesión de auditoría iniciada para este agente */
    private $_id_sesion = NULL;
    
    /* El ID del break en que se encuentre el agente, o NULL si no está en break */
    private $_id_break = NULL;
    
    /* El ID de la sesión de auditoría correspondiente al break del agente */
    private $_id_audit_break = NULL;
    
    /* El ID del hold en que se encuentra el agente, o NULL si no está en hold */
    private $_id_hold = NULL;
    
    /* El ID de la sesión de auditoría correspondiente al hold del agente */
    private $_id_audit_hold = NULL;
    
    /* El Uniqueid de la llamada que se usó para iniciar el login de agente */
    private $_Uniqueid = NULL;

    /* El canal que se usó para el login del agente */
    private $_login_channel = NULL;
    
    /* El Uniqueid de la llamada por parte del canal del agente que se contrapone 
     * al Uniqueid de la llamada generada o recibida. Para llamadas salientes
     * es sólo informativo, pero es esencial registrarlo para llamadas entrantes.
     * Sólo este Uniqueid recibe un Hangup cuando una llamada es transferida.
     */
    private $_UniqueidAgente = NULL;
    
    /* VERDADERO si el agente ha sido reservado para agendamiento */
    private $_reservado = FALSE;
    
    /* Cuenta de pausas del agente. El agente debe estar pausado si distinto de 
     * cero. Por ahora se usa para break y para hold. */
    private $_num_pausas = 0;

    /* Si no es NULL, máximo intervalo de inactividad, en segundos */
    private $_max_inactivo = NULL;
    
    /* Timestamp de la última actividad del agente */
    private $_ultima_actividad;

    /* Estado del agente en todas las colas a la que pertenece */
    private $_estado_agente_colas = array();
    
    /* Sólo agentes dinámicos: lista de colas dinámicas a las que debe pertenecer */
    private $_colas_dinamicas = array();
    
    var $llamada_agendada = NULL;

    function __construct(ListaAgentes $lista, $idAgente, $iNumero, $sNombre,
        $bEstatus, $sType = 'Agent')
    {
        $this->_listaAgentes = $lista;
        $this->_id_agent = (int)$idAgente;
        $this->_name = (string)$sNombre;
        $this->_estatus = (bool)$bEstatus;
        $this->_type = (string)$sType;
        $this->resetTimeout();

        // Se setea vía interfaz pública para invocar __set()
        $this->number = $iNumero;
    }
    
    private function _nul($i) { return is_null($i) ? '(ninguno)' : "$i"; }
    
    public function dump($log)
    {
        $s = "----- AGENTE -----\n";
        $s .= ("\tid_agent...........".$this->id_agent)."\n";
        $s .= ("\tname...............".$this->name)."\n";
        $s .= ("\testatus............".$this->estatus)."\n";
        $s .= ("\ttype...............".$this->type)."\n";
        $s .= ("\tnumber.............".$this->number)."\n";
        $s .= ("\tchannel............".$this->channel)."\n";
        if ($this->type != 'Agent')
            $s .= ("\tcolas dinámicas....[".implode(', ', $this->_colas_dinamicas))."]\n";
        $s .= ("\testado de colas....[".$this->_strestadocolas())."]\n";
        $s .= ("\testado_consola.....".$this->estado_consola)."\n";
        
        $s .= ("\tid_sesion..........".$this->_nul($this->id_sesion))."\n";
        $s .= ("\tid_break...........".$this->_nul($this->id_break))."\n";
        $s .= ("\tid_audit_break.....".$this->_nul($this->id_audit_break))."\n";
        $s .= ("\tid_hold............".$this->_nul($this->id_hold))."\n";
        $s .= ("\tid_audit_hold......".$this->_nul($this->id_audit_hold))."\n";
        $s .= ("\tUniqueid...........".$this->_nul($this->Uniqueid))."\n";
        $s .= ("\tUniqueidAgente.....".$this->_nul($this->UniqueidAgente))."\n";
        $s .= ("\tlogin_channel......".$this->_nul($this->login_channel))."\n";
        $s .= ("\textension..........".$this->_nul($this->extension))."\n";
        $s .= ("\tnum_pausas.........".$this->num_pausas)."\n";
        $s .= ("\ten_pausa...........".($this->en_pausa ? 'SI' : 'NO'))."\n";
        $s .= ("\treservado..........".($this->reservado ? 'SI' : 'NO'))."\n";
        $s .= ("\tmax_inactivo.......".$this->_nul($this->max_inactivo))."\n";
        $s .= ("\ttimeout_inactivo...".$this->timeout_inactivo)."\n";

        $s .= ("\tllamada............".(is_null($this->_llamada)
            ? '(ninguna)'
            : $this->_llamada->__toString()
            ));
        $log->output($s);
    }
    
    public function __toString()
    {
        return 'ID='.$this->id_agent.
            ' type='.$this->type.
            ' number='.$this->_nul($this->number).
            ' '.$this->name;
    }
    
    private function _strestadocolas()
    {
        // El estado de la cola sólo es usable si eventmemberstatus está activo
        // para la cola en cuestión.
        /*
        $states = array(
            -1  =>  'Not in queue',
            0   =>  "Unknown",
            1   =>  "Not in use",
            2   =>  "In use",
            3   =>  "Busy",
            4   =>  "Invalid",
            5   =>  "Unavailable",
            6   =>  "Ringing",
            7   =>  "Ring+Inuse",
            8   =>  "On Hold",
        );
        $s = array();
        foreach ($this->_estado_agente_colas as $q => $st)
            $s[] = "$q (".$states[$st].")";
        return implode(', ', $s);
        */
        return implode(', ', array_keys($this->_estado_agente_colas));
    }
    
    public function __get($s)
    {
        switch ($s) {
        case 'id_agent':        return $this->_id_agent;
        case 'number':          return $this->_number;
        case 'channel':         return is_null($this->_number) ? NULL : $this->_type.'/'.$this->_number;
        case 'type':            return $this->_type;
        case 'name':            return $this->_name;
        case 'estatus':         return $this->_estatus;
        case 'estado_consola':  return $this->_estado_consola;
        case 'id_sesion':       return $this->_id_sesion;
        case 'id_break':        return $this->_id_break;
        case 'id_audit_break':  return $this->_id_audit_break;
        case 'id_hold':         return $this->_id_hold;
        case 'id_audit_hold':   return $this->_id_audit_hold;
        case 'Uniqueid':        return $this->_Uniqueid;
        case 'UniqueidAgente':  return $this->_UniqueidAgente;
        case 'llamada':         return $this->_llamada;
        case 'login_channel':   return $this->_login_channel;
        case 'extension':       return $this->_extension;
        case 'num_pausas':      return $this->_num_pausas;
        case 'en_pausa':        return ($this->_num_pausas > 0);
        case 'reservado':       return $this->_reservado;
        case 'max_inactivo':    return $this->_max_inactivo;
        case 'timeout_inactivo':return (!is_null($this->_max_inactivo) && (time() >= $this->_ultima_actividad + $this->_max_inactivo));
        default:
            die(__METHOD__.' - propiedad no implementada: '.$s);
        }
    }
    
    public function __set($s, $v)
    {
        switch ($s) {
        case 'id_agent':        $this->_id_agent = (int)$v; break;
        case 'id_sesion':       $this->_id_sesion = is_null($v) ? NULL : (int)$v; break;
        case 'name':            $this->_name = (string)$v; break;
        case 'estatus':         $this->_estatus = (bool)$v; break;
        case 'max_inactivo':    $this->_max_inactivo = is_null($v) ? NULL : (int)$v; break;
        case 'number':          
            if (ctype_digit("$v")) {
                $v = (string)$v;
                $sCanalViejo = $this->channel;
                $this->_number = $v;
                $sCanalNuevo = $this->channel;
                
                if (!is_null($sCanalViejo))
                    $this->_listaAgentes->removerIndice('agentchannel', $sCanalViejo);
                $this->_listaAgentes->agregarIndice('agentchannel', $sCanalNuevo, $this);
            }
            break;
        case 'reservado':
            $v = (bool)$v;
            if ($this->_reservado != $v) {
            	$this->_reservado = $v;
                if ($this->_reservado) {
                	$this->_num_pausas++;
                } else {
                	if ($this->_num_pausas >= 0) $this->_num_pausas--;
                }
            }
            break;
        default:
            die(__METHOD__.' - propiedad no implementada: '.$s);
        }
    }
    
    public function resetTimeout() { $this->_ultima_actividad = time(); }
    
    public function setBreak($id_break, $id_audit_break)
    {
    	if (!is_null($id_break) && !is_null($id_audit_break)) {
    		$this->_id_break = (int)$id_break;
            $this->_id_audit_break = (int)$id_audit_break;
            $this->_num_pausas++;
    	} else {
            $this->clearBreak();
    	}
        $this->resetTimeout();
    }
    
    public function clearBreak()
    {
        $this->_id_break = NULL;
        $this->_id_audit_break = NULL;
        if ($this->_num_pausas >= 0) $this->_num_pausas--;
        $this->resetTimeout();
    }

    public function setHold($id_hold, $id_audit_hold)
    {
        if (!is_null($id_hold) && !is_null($id_audit_hold)) {
            $this->_id_hold = (int)$id_hold;
            $this->_id_audit_hold = (int)$id_audit_hold;
            $this->_num_pausas++;
            if (!is_null($this->_llamada))
                $this->_llamada->request_hold = TRUE;
        } else {
            $this->clearHold();
        }
        $this->resetTimeout();
    }
    
    public function clearHold()
    {
        $this->_id_hold = NULL;
        $this->_id_audit_hold = NULL;
        if ($this->_num_pausas >= 0) $this->_num_pausas--;
        if (!is_null($this->_llamada)) {
            $this->_llamada->request_hold = FALSE;
            $this->_llamada->status = 'Success';
        }
        $this->resetTimeout();
    }
    
    public function iniciarLoginAgente($sExtension)
    {
    	$this->_estado_consola = 'logged-out';
        if (!is_null($this->_Uniqueid))
            $this->_listaAgentes->removerIndice('uniqueidlogin', $this->_Uniqueid);
        $this->_Uniqueid = NULL;
        $this->_login_channel = NULL;
        $this->_extension = $sExtension;
        $this->_listaAgentes->agregarIndice('extension', $sExtension, $this);
    }
    
    // Se llama en OriginateResponse exitoso, o en Hangup antes de completar login
    public function respuestaLoginAgente($response, $uniqueid, $channel)
    {
    	if ($response == 'Success') {
    		// El sistema espera ahora la contraseña del agente
            $this->_estado_consola = 'logging';
            $this->_Uniqueid = $uniqueid;
            $this->_listaAgentes->agregarIndice('uniqueidlogin', $uniqueid, $this);
            $this->_login_channel = $channel;
    	} else {
    		// El agente no ha podido responder la llamada de login
            $this->_estado_consola = 'logged-out';
            if (!is_null($this->_Uniqueid))
                $this->_listaAgentes->removerIndice('uniqueidlogin', $this->_Uniqueid);
            $this->_Uniqueid = NULL;
            $this->_login_channel = NULL;
            if (!is_null($this->_extension))
                $this->_listaAgentes->removerIndice('extension', $this->_extension);
            $this->_extension = NULL;
        }
    }
    
    // Se llama en Agentlogin al confirmar que agente está logoneado
    public function completarLoginAgente()
    {
    	$this->_estado_consola = 'logged-in';
        $this->resetTimeout();
    }
    
    // Se llama en Agentlogoff
    public function terminarLoginAgente()
    {
        $this->clearBreak();
        $this->clearHold();
        $this->_estado_consola = 'logged-out';
        $this->_num_pausas = 0;
        if (!is_null($this->_Uniqueid))
            $this->_listaAgentes->removerIndice('uniqueidlogin', $this->_Uniqueid);
        $this->_Uniqueid = NULL;
        $this->_login_channel = NULL;
        if (!is_null($this->_extension))
            $this->_listaAgentes->removerIndice('extension', $this->_extension);
        $this->_extension = NULL;
        $this->_id_sesion = NULL;
        $this->resetTimeout();
    }
    
    public function resumenSeguimiento()
    {
        return array(
            'id_agent'          =>  $this->id_agent,
            'estado_consola'    =>  $this->estado_consola,
            'id_break'          =>  $this->id_break,
            'id_audit_break'    =>  $this->id_audit_break,
            'id_hold'           =>  $this->id_hold,
            'id_audit_hold'     =>  $this->id_audit_hold,
            'num_pausas'        =>  $this->num_pausas,
            'extension'         =>  $this->extension,
            'login_channel'     =>  $this->login_channel,
            'oncall'            =>  !is_null($this->llamada),
            'clientchannel'     =>  is_null($this->llamada) ? NULL : $this->llamada->actualchannel,
        );
    }
    
    public function asignarLlamadaAtendida($llamada, $uniqueid_agente)
    {
    	$this->_llamada = $llamada;
        $this->llamada_agendada = NULL;
        if (!is_null($this->_UniqueidAgente))
            $this->_listaAgentes->removerIndice('uniqueidlink', $this->_UniqueidAgente);
        $this->_UniqueidAgente = $uniqueid_agente;
        $this->_listaAgentes->agregarIndice('uniqueidlink', $uniqueid_agente, $this);
        $this->resetTimeout();
    }
    
    public function quitarLlamadaAtendida()
    {
        $this->_llamada = NULL;
        $this->llamada_agendada = NULL;
        if (!is_null($this->_UniqueidAgente))
            $this->_listaAgentes->removerIndice('uniqueidlink', $this->_UniqueidAgente);
        $this->_UniqueidAgente = NULL;
        $this->resetTimeout();
    }

    public function asignarEstadoEnColas($nuevoEstado)
    {
        $this->_estado_agente_colas = $nuevoEstado;
        asort($this->_estado_agente_colas);
    }
    
    public function actualizarEstadoEnCola($queue, $status)
    {
        $this->_estado_agente_colas[$queue] = $status;
    }
    
    public function quitarEstadoEnCola($queue)
    {
        unset($this->_estado_agente_colas[$queue]);
    }
    
    // El estado de la cola sólo es usable si eventmemberstatus está activo
    // para la cola en cuestión. Excepto que siempre se sabe si está o no en cola.
    public function estadoEnCola($queue)
    {
        return isset($this->_estado_agente_colas[$queue]) ? AST_DEVICE_NOTINQUEUE : $this->_estado_agente_colas[$queue];
    }
    
    public function asignarColasDinamicas($lista)
    {
        $this->_colas_dinamicas = $lista;
        sort($this->_colas_dinamicas);
    }
    
    public function diferenciaColasDinamicas()
    {
        if ($this->type == 'Agent') return NULL;
        if ($this->estado_consola != 'logged-in') return NULL;
        $currcolas = $this->listaColasAgente();
        return array(
            array_diff($this->_colas_dinamicas, $currcolas), // colas a las cuales agregar agente
            array_diff($currcolas, $this->_colas_dinamicas), // colas de las cuales quitar agente
        );
    }
    
    public function hayColasDinamicasLogoneadas()
    {
        $currcolas = array_keys($this->_estado_agente_colas);
        return (count(array_intersect($currcolas, $this->_colas_dinamicas)) > 0);
    }
    
    // Colas a las cuales pertenece actualmente el agente
    public function listaColasAgente()
    {
        return array_keys($this->_estado_agente_colas);
    }
}
?>