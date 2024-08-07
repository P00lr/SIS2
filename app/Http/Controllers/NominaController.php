<?php

namespace App\Http\Controllers;

use App\Models\Descuento;
use App\Models\Asistencia;
use App\Models\Licencia;
use App\Models\detalleNomina;
use Illuminate\Http\Request;
use App\Models\Nomina;
use App\Models\Empleado;
use Carbon\Carbon;
use GuzzleHttp\Promise\Create;
use Illuminate\Support\Facades\DB;
use App\Models\boletaPago;


class NominaController extends Controller
{

    public function index()
    {
        $empleados = Empleado::withCount('asistencias')
            ->having('asistencias_count', '<=', 30)
            ->get();

        // Calculamos y asignamos los atributos adicionales a cada empleado
        foreach ($empleados as $empleado) {
            // Obtenemos los datos laborales de cada empleado
            $datosLaborales = $empleado->datosLaborales;

            // Validamos que existan datos laborales para el empleado
            if ($datosLaborales) {
                // guardando el sueldo
                $sueldo = $datosLaborales->salario;

                // ganado por dia
                $pagoPorDia = $sueldo / 30;

                // Calculo de los años de antigüedad
                $antiguedad = Carbon::parse($datosLaborales->fechaContratacion)->diffInYears(Carbon::now());

                // Haciendo el cálculo del bono de antigüedad
                $bonoAntiguedad = ($antiguedad >= 1) ? $sueldo * 0.05 * $antiguedad : 0;

                // no lo toma en cuenta si no tienes días trabajados
                if ($empleado->asistencias_count > 0) {
                    $totalGanado = ($pagoPorDia * $empleado->asistencias_count) + $bonoAntiguedad;

                    // Obtener el total de descuentos aplicados a la nómina del empleado
                    $totalDescuentos = $totalGanado * 0.1271 + $totalGanado * 0.13;


                    $liquidoPagable = $totalGanado - $totalDescuentos;
                } else {
                    $totalGanado = 0;
                    $totalDescuentos = 0;
                    $bonoAntiguedad = 0;
                    $descuentoAFP = 0;
                    $descuentoRCIVA = 0;
                    $liquidoPagable = 0;
                }

                // Asignar estos valores al objeto Empleado
                $empleado->sueldo = $sueldo;
                $empleado->diasTrabajados = $empleado->asistencias_count;
                $empleado->bonoAntiguedad = $bonoAntiguedad;
                $empleado->totalGanado = $totalGanado;
                $empleado->afp = $totalGanado * 0.1271;
                $empleado->rc_iva = $totalGanado * 0.13;
                $empleado->descuentoAdicional = 0;
                $empleado->totalDescuento = $totalDescuentos;
                $empleado->liquidoPagable = $liquidoPagable;
            }
            /*  return $empleados->nomina->estado; */
        }
        // Almacenar los datos en la sesión
        session()->put('empleados_calculados', $empleados); //solo agregue esta linea

        return view('nomina.index', compact('empleados'));
    }


    public function aplicarDescuentos(Request $request)
    {
        // Recuperar los datos calculados de la sesión
        $empleados = session()->get('empleados_calculados', []);

        // Validar los datos del formulario
        $datosDescuentos =  $validated = $request->validate([
            'empleado_id' => 'required|exists:empleados,id',
            'monto' => 'required|numeric|min:0',
            'tipoDescuento' => 'required|string',
            'descripcion' => 'nullable|string',
        ]);


        // Encontrar el empleado en los datos almacenados en la sesión
        $empleado = $empleados->firstWhere('id', $validated['empleado_id']);

        $descuento = Descuento::create([
            'empleado_id' => $validated['empleado_id'],
            'monto' => $validated['monto'],
            'tipoDescuento' => $validated['tipoDescuento'],
            'descripcion' => $validated['descripcion'],
            'fecha' => now()
        ]);

        if ($empleado) {
            // Aplicar el descuento temporalmente
            $empleado->descuentoAdicional += $validated['monto'];
            $empleado->totalDescuento += $validated['monto'];
            $empleado->liquidoPagable -= $validated['monto'];

            return view('nomina.index', compact('empleados'));
        } else {
            return redirect()->back()->withErrors(['empleado_id' => 'Empleado no encontrado.']);
        }
    }
    public function store(Request $request)
    {
        // Crear una nueva nómina
        $nomina = Nomina::create([
            'fecha' => now(),
            'estado' => true,
        ]);

        // Iterar sobre los empleados enviados en la solicitud
        foreach ($request->input('empleados') as $empleadoData) {

            // Guardar el detalle de la nómina
            DetalleNomina::create([
                'empleado_id' => $empleadoData['id'],
                'nomina_id' => $nomina->id, // Usar el ID de la nómina recién creada
                'diasTrabajados' => $empleadoData['diasTrabajados'] ?? 0,
                'bonoAntiguedad' => $empleadoData['bonoAntiguedad'],
                'totalGanado' => $empleadoData['totalGanado'],
                'afp' => $empleadoData['afp'],
                'rc_iva' => $empleadoData['rc_iva'],
                'descuentoAdicional' => $empleadoData['descuentoAdicional'],
                'totalDescuento' => $empleadoData['totalDescuento'],
                'liquidoPagable' => $empleadoData['liquidoPagable'],
            ]);

            // Borrar todas las asistencias del empleado
            $empleado = Empleado::find($empleadoData['id']);
            if ($empleado) {
                $empleado->asistencias()->delete();
            }
        }

        return redirect()->route('nomina.index')->with('success', 'Planilla guardada y días trabajados reiniciados exitosamente');
    }

    public function filtro(Request $request)
    {
        $anio = $request->input('anio');
        $mes = $request->input('mes');

        // Obtener los IDs de nómina que coinciden con el año y mes
        $nominas = Nomina::whereYear('fecha', $anio)
            ->whereMonth('fecha', $mes)
            ->pluck('id');

        // Obtener los detalles de la nómina con los empleados relacionados y boleta de pago
        $detalleNominas = DetalleNomina::whereIn('nomina_id', $nominas)
            ->with(['empleado', 'boletaPago']) // Incluye la relación con BoletaPago
            ->get();

        // Guardar los datos del filtro en la sesión
        session()->put('datosDelFiltro', $request->all());

        return view('nomina.filtro', ['detalleNominas' => $detalleNominas]);
    }
}
