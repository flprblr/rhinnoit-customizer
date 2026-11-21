<?php
/**
 * Turns the commune field of a Chilean address into a dropdown, like the region.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer\Frontend;

use RhinnoIT\Customizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Offers the communes of the chosen region in a dropdown on the classic checkout.
 *
 * WooCommerce draws the region (the state) of a Chilean address as a
 * dropdown and the commune (the city) as free text, so the same commune
 * reaches the orders spelt a dozen ways - "Ñuñoa", "nunoa", "Santiago
 * centro" - and a typo is only found by whoever ships it. With this on, once
 * the country is Chile and a region is chosen, the text box is swapped for a
 * dropdown with that region's communes, enhanced by selectWoo as WooCommerce
 * enhances the region. It keeps the field's id and name, so the label, the
 * Checkout Field Editor's settings and the order are untouched: the order
 * gets the commune's name as before.
 *
 * A value already in the box - a returning customer's saved address - is
 * picked in the list when it names a commune of the region, ignoring case
 * and accents; otherwise the dropdown waits for a choice. Changing the
 * region rebuilds the list, and any other country gives the text box back.
 *
 * It also steps aside, and gives the text box back, unless every region the
 * dropdown offers is one of WooCommerce's 16 codes (CL-RM and the rest). A
 * plugin that redefines Chile's regions under codes of its own - hoka's
 * shipping zones still name CL_RM, CL_MA and others, left by one - would
 * otherwise get a dropdown with no communes, and a checkout nobody could
 * finish.
 *
 * Server-side nothing changes and nothing is validated: without the script
 * (a blocked file, an old browser) the box stays a text box and the shopper
 * can still buy. The block checkout draws its own fields and never loads
 * wc-checkout, so there it does nothing.
 *
 * The list is the 346 communes, keyed by WooCommerce's codes for the 16
 * regions. The shipping rules' commune lists on the screen offer the same
 * names, so with this on, the city a package ships to is one of them.
 */
final class Commune_Select {

	/**
	 * WooCommerce's classic checkout script, which the dropdown's script follows.
	 */
	const string HANDLE = 'wc-checkout';

	/**
	 * The script; __COMMUNES__ is the list, __CHOOSE__ and __REGION__ the placeholders, as JSON.
	 *
	 * Runs for the billing and shipping addresses after every region or
	 * country change, and once the page is ready. WooCommerce swaps the
	 * region field itself when the country changes and fires
	 * country_to_state_changed, so the field is looked up again each time.
	 * K() answers whether every region the dropdown offers is a key of the
	 * list; n() folds case, accents and punctuation for matching a saved
	 * commune, the same way on both sides.
	 */
	const string SCRIPT = 'window.jQuery&&(function($){var C=__COMMUNES__,P=__CHOOSE__,R=__REGION__,k="rcus-commune-select",I={};'
		. 'function n(v){v=String(v||"").toLowerCase();if(v.normalize)v=v.normalize("NFD").replace(/[^ -~]/g,"");return v.replace(/[^a-z0-9]+/g," ").trim()}'
		. 'function K(s){var j=0,r=s.is("select");s.find("option").each(function(){if(""!==this.value){j++;if(!Object.prototype.hasOwnProperty.call(C,this.value))r=!1}});return r&&0<j}'
		. 'function u(p){var c=$("#"+p+"_country"),s=$("#"+p+"_state"),f=$("#"+p+"_city");if(!f.length)return;'
		. 'var l="CL"===c.val()&&K(s)?(C[s.val()]||[]):null,e=f.is("select."+k);'
		. 'if(!l){if(e&&I[p]){if(f.data("select2"))f.selectWoo("destroy");f.replaceWith(I[p].val(f.val()||""))}return}'
		. 'if(!e&&!f.is("input"))return;var v=f.val(),t=n(v),o=$("<option value=\'\'></option>").text(s.val()?P:R),g=e?f:$(\'<select class="\'+k+\'"></select>\').attr({id:f.attr("id"),name:f.attr("name"),autocomplete:f.attr("autocomplete")||null,"aria-required":f.attr("aria-required")||null});'
		. 'g.empty().append(o);$.each(l,function(i,m){g.append($("<option></option>").val(m).text(m).prop("selected",""!==t&&n(m)===t))});'
		. 'if(!e){I[p]=f;f.replaceWith(g)}'
		. 'if($.fn.selectWoo){if(g.data("select2"))g.selectWoo("destroy");g.selectWoo({width:"100%",placeholder:s.val()?P:R,language:{noResults:function(){return(window.wc_country_select_params||{}).i18n_no_matches||""}}})}}'
		. 'function a(){setTimeout(function(){try{u("billing");u("shipping")}catch(x){}},0)}'
		. '$(document.body).on("change","#billing_state,#shipping_state,#billing_country,#shipping_country",a).on("country_to_state_changed",a);$(a)})(jQuery);';

	/**
	 * Chile's communes by WooCommerce's region code.
	 *
	 * @var array<string, list<string>>
	 */
	const array COMMUNES = array(
		'CL-AP' => array( 'Arica', 'Camarones', 'General Lagos', 'Putre' ),
		'CL-TA' => array( 'Alto Hospicio', 'Camiña', 'Colchane', 'Huara', 'Iquique', 'Pica', 'Pozo Almonte' ),
		'CL-AN' => array( 'Antofagasta', 'Calama', 'María Elena', 'Mejillones', 'Ollagüe', 'San Pedro de Atacama', 'Sierra Gorda', 'Taltal', 'Tocopilla' ),
		'CL-AT' => array( 'Alto del Carmen', 'Caldera', 'Chañaral', 'Copiapó', 'Diego de Almagro', 'Freirina', 'Huasco', 'Tierra Amarilla', 'Vallenar' ),
		'CL-CO' => array( 'Andacollo', 'Canela', 'Combarbalá', 'Coquimbo', 'Illapel', 'La Higuera', 'La Serena', 'Los Vilos', 'Monte Patria', 'Ovalle', 'Paihuano', 'Punitaqui', 'Río Hurtado', 'Salamanca', 'Vicuña' ),
		'CL-VS' => array( 'Algarrobo', 'Cabildo', 'Calle Larga', 'Cartagena', 'Casablanca', 'Catemu', 'Concón', 'El Quisco', 'El Tabo', 'Hijuelas', 'Isla de Pascua', 'Juan Fernández', 'La Calera', 'La Cruz', 'La Ligua', 'Limache', 'Llaillay', 'Los Andes', 'Nogales', 'Olmué', 'Panquehue', 'Papudo', 'Petorca', 'Puchuncaví', 'Putaendo', 'Quillota', 'Quilpué', 'Quintero', 'Rinconada', 'San Antonio', 'San Esteban', 'San Felipe', 'Santa María', 'Santo Domingo', 'Valparaíso', 'Villa Alemana', 'Viña del Mar', 'Zapallar' ),
		'CL-RM' => array( 'Alhué', 'Buin', 'Calera de Tango', 'Cerrillos', 'Cerro Navia', 'Colina', 'Conchalí', 'Curacaví', 'El Bosque', 'El Monte', 'Estación Central', 'Huechuraba', 'Independencia', 'Isla de Maipo', 'La Cisterna', 'La Florida', 'La Granja', 'La Pintana', 'La Reina', 'Lampa', 'Las Condes', 'Lo Barnechea', 'Lo Espejo', 'Lo Prado', 'Macul', 'Maipú', 'María Pinto', 'Melipilla', 'Ñuñoa', 'Padre Hurtado', 'Paine', 'Pedro Aguirre Cerda', 'Peñaflor', 'Peñalolén', 'Pirque', 'Providencia', 'Pudahuel', 'Puente Alto', 'Quilicura', 'Quinta Normal', 'Recoleta', 'Renca', 'San Bernardo', 'San Joaquín', 'San José de Maipo', 'San Miguel', 'San Pedro', 'San Ramón', 'Santiago', 'Talagante', 'Tiltil', 'Vitacura' ),
		'CL-LI' => array( 'Chépica', 'Chimbarongo', 'Codegua', 'Coinco', 'Coltauco', 'Doñihue', 'Graneros', 'La Estrella', 'Las Cabras', 'Litueche', 'Lolol', 'Machalí', 'Malloa', 'Marchigüe', 'Mostazal', 'Nancagua', 'Navidad', 'Olivar', 'Palmilla', 'Paredones', 'Peralillo', 'Peumo', 'Pichidegua', 'Pichilemu', 'Placilla', 'Pumanque', 'Quinta de Tilcoco', 'Rancagua', 'Rengo', 'Requínoa', 'San Fernando', 'San Vicente', 'Santa Cruz' ),
		'CL-ML' => array( 'Cauquenes', 'Chanco', 'Colbún', 'Constitución', 'Curepto', 'Curicó', 'Empedrado', 'Hualañé', 'Licantén', 'Linares', 'Longaví', 'Maule', 'Molina', 'Parral', 'Pelarco', 'Pelluhue', 'Pencahue', 'Rauco', 'Retiro', 'Río Claro', 'Romeral', 'Sagrada Familia', 'San Clemente', 'San Javier', 'San Rafael', 'Talca', 'Teno', 'Vichuquén', 'Villa Alegre', 'Yerbas Buenas' ),
		'CL-NB' => array( 'Bulnes', 'Chillán', 'Chillán Viejo', 'Cobquecura', 'Coelemu', 'Coihueco', 'El Carmen', 'Ninhue', 'Ñiquén', 'Pemuco', 'Pinto', 'Portezuelo', 'Quillón', 'Quirihue', 'Ránquil', 'San Carlos', 'San Fabián', 'San Ignacio', 'San Nicolás', 'Treguaco', 'Yungay' ),
		'CL-BI' => array( 'Alto Biobío', 'Antuco', 'Arauco', 'Cabrero', 'Cañete', 'Chiguayante', 'Concepción', 'Contulmo', 'Coronel', 'Curanilahue', 'Florida', 'Hualpén', 'Hualqui', 'Laja', 'Lebu', 'Los Álamos', 'Los Ángeles', 'Lota', 'Mulchén', 'Nacimiento', 'Negrete', 'Penco', 'Quilaco', 'Quilleco', 'San Pedro de la Paz', 'San Rosendo', 'Santa Bárbara', 'Santa Juana', 'Talcahuano', 'Tirúa', 'Tomé', 'Tucapel', 'Yumbel' ),
		'CL-AR' => array( 'Angol', 'Carahue', 'Cholchol', 'Collipulli', 'Cunco', 'Curacautín', 'Curarrehue', 'Ercilla', 'Freire', 'Galvarino', 'Gorbea', 'Lautaro', 'Loncoche', 'Lonquimay', 'Los Sauces', 'Lumaco', 'Melipeuco', 'Nueva Imperial', 'Padre Las Casas', 'Perquenco', 'Pitrufquén', 'Pucón', 'Purén', 'Renaico', 'Saavedra', 'Temuco', 'Teodoro Schmidt', 'Toltén', 'Traiguén', 'Victoria', 'Vilcún', 'Villarrica' ),
		'CL-LR' => array( 'Corral', 'Futrono', 'La Unión', 'Lago Ranco', 'Lanco', 'Los Lagos', 'Máfil', 'Mariquina', 'Paillaco', 'Panguipulli', 'Río Bueno', 'Valdivia' ),
		'CL-LL' => array( 'Ancud', 'Calbuco', 'Castro', 'Chaitén', 'Chonchi', 'Cochamó', 'Curaco de Vélez', 'Dalcahue', 'Fresia', 'Frutillar', 'Futaleufú', 'Hualaihué', 'Llanquihue', 'Los Muermos', 'Maullín', 'Osorno', 'Palena', 'Puerto Montt', 'Puerto Octay', 'Puerto Varas', 'Puqueldón', 'Purranque', 'Puyehue', 'Queilén', 'Quellón', 'Quemchi', 'Quinchao', 'Río Negro', 'San Juan de la Costa', 'San Pablo' ),
		'CL-AI' => array( 'Aysén', 'Chile Chico', 'Cisnes', 'Cochrane', 'Coyhaique', 'Guaitecas', 'Lago Verde', "O'Higgins", 'Río Ibáñez', 'Tortel' ),
		'CL-MA' => array( 'Antártica', 'Cabo de Hornos', 'Laguna Blanca', 'Natales', 'Porvenir', 'Primavera', 'Punta Arenas', 'Río Verde', 'San Gregorio', 'Timaukel', 'Torres del Paine' ),
	);

	/**
	 * Hooks the script while the switch is on.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::feature( 'checkout_commune_select' ) ) {
			return;
		}

		// After WooCommerce enqueues wc-checkout, at 10.
		add_action( 'wp_enqueue_scripts', array( $this, 'add_script' ), 100 );
	}

	/**
	 * WooCommerce's names for Chile's regions, by code: what the screen's commune lists head their groups with.
	 *
	 * The group_labels callback of the shipping rules' commune lists, which
	 * the form calls when it draws them. Read from WooCommerce, in the
	 * admin's language; a region it does not name - a plugin redefining
	 * Chile's regions under codes of its own - keeps its code.
	 *
	 * @return array<mixed>
	 */
	public static function region_names(): array {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		$countries   = $woocommerce instanceof \WooCommerce ? $woocommerce->countries : null;
		$names       = $countries instanceof \WC_Countries ? $countries->get_states( 'CL' ) : null;

		return is_array( $names ) ? $names : array();
	}

	/**
	 * Prints the script after WooCommerce's checkout script, on the checkout only.
	 *
	 * @return void
	 */
	public function add_script(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		$communes = wp_json_encode( self::COMMUNES, JSON_UNESCAPED_UNICODE );
		$choose   = wp_json_encode( __( 'Choose a commune…', 'rhinnoit-customizer' ), JSON_UNESCAPED_UNICODE );
		$region   = wp_json_encode( __( 'Choose the region first', 'rhinnoit-customizer' ), JSON_UNESCAPED_UNICODE );

		if ( false === $communes || false === $choose || false === $region ) {
			return;
		}

		$script = strtr(
			self::SCRIPT,
			array(
				'__COMMUNES__' => $communes,
				'__CHOOSE__'   => $choose,
				'__REGION__'   => $region,
			)
		);

		wp_add_inline_script( self::HANDLE, $script, 'after' );
	}
}
