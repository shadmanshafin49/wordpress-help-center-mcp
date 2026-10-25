/**
 * AI & MCP page: replays the recorded session, runs the live MCP console and, when the
 * site has an Anthropic API key, the live assistant. Data comes from showcase.php.
 */
( function () {
	'use strict';

	const data = window.helpCenterShowcase;

	if ( ! data ) {
		return;
	}

	const reduceMotion = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	const wait = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, reduceMotion ? 0 : ms ) );

	function el( tag, className, text ) {
		const node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( undefined !== text ) {
			node.textContent = text;
		}
		return node;
	}

	function escapeHtml( text ) {
		return String( text ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}

	/** Pretty-printed JSON with syntax highlighting, as HTML. */
	function jsonHtml( value ) {
		const json = JSON.stringify( value, null, 2 ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );

		return json.replace(
			/("(?:\\u[\da-fA-F]{4}|\\[^u]|[^\\"])*")(\s*:)?|\b(true|false|null)\b|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/g,
			( match, string, colon, literal, number ) => {
				if ( string ) {
					return '<span class="' + ( colon ? 'hc-key' : 'hc-str' ) + '">' + string + '</span>' + ( colon || '' );
				}
				return literal ? '<span class="hc-lit">' + literal + '</span>' : '<span class="hc-num">' + number + '</span>';
			}
		);
	}

	/** The small part of Markdown that answers use: paragraphs, "- " lists, **bold** and [links](url). */
	function markdownHtml( text ) {
		const inline = ( line ) =>
			escapeHtml( line )
				.replace( /\*\*(.+?)\*\*/g, '<strong>$1</strong>' )
				.replace( /\[([^\]]+)\]\(((?:https?:\/\/|\/)[^\s)]+)\)/g, '<a href="$2">$1</a>' );

		return text
			.trim()
			.split( /\n{2,}/ )
			.map( ( block ) => {
				const lines = block.split( '\n' );
				if ( lines.every( ( line ) => /^\s*[-*] /.test( line ) ) ) {
					return '<ul>' + lines.map( ( line ) => '<li>' + inline( line.replace( /^\s*[-*] /, '' ) ) + '</li>' ).join( '' ) + '</ul>';
				}
				return '<p>' + lines.map( inline ).join( '<br>' ) + '</p>';
			} )
			.join( '' );
	}

	/** A short label for an MCP request, e.g. 'Search for "refund"'. */
	function describe( exchange ) {
		const params = exchange.request.body.params || {};
		const args = params.arguments || {};

		switch ( exchange.request.body.method ) {
			case 'initialize':
				return 'Connect and agree on a protocol version';
			case 'tools/list':
				return 'List the tools';
			case 'resources/list':
				return 'List the resources';
			case 'prompts/list':
				return 'List the prompts';
			case 'resources/read':
				return 'Read ' + params.uri;
			case 'prompts/get':
				return 'Fill in the prompt ' + params.name;
		}

		switch ( params.name ) {
			case 'help-center-search-articles':
				return 'Search for "' + args.query + '"' + ( args.topic ? ' in topic "' + args.topic + '"' : '' );
			case 'help-center-get-article':
				return 'Read article #' + args.id;
			case 'help-center-list-topics':
				return 'List the topics';
			case 'help-center-create-draft':
				return 'Save a draft';
		}

		return 'Call ' + params.name;
	}

	/** What came back, in a few words. */
	function outcome( exchange ) {
		const response = exchange.response || {};
		const result = response.result || {};

		if ( response.error || exchange.status >= 400 ) {
			return { error: true, text: ( response.error && response.error.message ) || response.message || 'HTTP ' + exchange.status };
		}
		if ( result.isError ) {
			return { error: true, text: result.content[ 0 ].text };
		}

		const content = result.structuredContent || {};

		if ( Array.isArray( content.articles ) ) {
			const titles = content.articles.map( ( article ) => article.title );
			return { text: titles.length ? titles.length + ( 1 === titles.length ? ' article: ' : ' articles: ' ) + titles.join( ', ' ) : 'No articles match' };
		}
		if ( Array.isArray( content.topics ) ) {
			return { text: content.topics.map( ( topic ) => topic.slug ).join( ', ' ) };
		}
		if ( 'draft' === content.status ) {
			return { text: 'Draft #' + content.id + ' saved. Not published.' };
		}
		if ( content.title ) {
			return { text: '"' + content.title + '"' };
		}
		if ( result.tools ) {
			return { text: result.tools.map( ( tool ) => tool.name ).join( ', ' ) };
		}
		if ( result.resources ) {
			return { text: result.resources.map( ( resource ) => resource.uri ).join( ', ' ) };
		}
		if ( result.prompts ) {
			return { text: result.prompts.map( ( prompt ) => prompt.name ).join( ', ' ) };
		}
		if ( result.contents ) {
			return { text: result.contents[ 0 ].mimeType + ', ' + result.contents[ 0 ].text.length + ' characters' };
		}
		if ( result.messages ) {
			return { text: 'A message ready to send to the model' };
		}
		if ( result.protocolVersion ) {
			return { text: result.serverInfo.name + ', protocol ' + result.protocolVersion };
		}

		return { text: 'OK' };
	}

	/** Both sides of an exchange as they travel over HTTP. */
	function exchangeView( exchange ) {
		const headers = ( list ) =>
			Object.entries( list || {} )
				.map( ( [ name, value ] ) => escapeHtml( name ) + ': ' + escapeHtml( value ) + '\n' )
				.join( '' );
		const view = el( 'div', 'hc-exchange' );

		view.innerHTML =
			'<div class="hc-pane"><p class="hc-pane__title">Request</p><pre><code>' +
			'<span class="hc-http">POST /wp-json/help-center/mcp</span>\n' +
			headers( exchange.request.headers ) +
			'\n' +
			jsonHtml( exchange.request.body ) +
			'</code></pre></div>' +
			'<div class="hc-pane"><p class="hc-pane__title">Response <span>HTTP ' +
			exchange.status +
			( undefined !== exchange.duration_ms ? ' · ' + exchange.duration_ms + ' ms' : '' ) +
			'</span></p><pre><code>' +
			( exchange.response_headers ? headers( exchange.response_headers ) + '\n' : '' ) +
			( null === exchange.response ? '(no body)' : jsonHtml( exchange.response ) ) +
			'</code></pre></div>';

		return view;
	}

	/** One MCP request in a transcript: a summary line that opens into the full exchange. */
	function callView( exchange ) {
		const view = el( 'details', 'hc-call' );
		const summary = el( 'summary' );
		const result = el( 'span', 'hc-call__result' );

		summary.append( el( 'span', 'hc-call__method', exchange.request.body.method ), el( 'span', 'hc-call__label', describe( exchange ) ), result );
		view.append( summary );
		view.addEventListener( 'toggle', () => {
			if ( view.open && ! view.querySelector( '.hc-exchange' ) ) {
				view.append( exchangeView( exchange ) );
			}
		} );

		view.showResult = () => {
			const { error, text } = outcome( exchange );
			view.classList.toggle( 'hc-call--error', !! error );
			result.textContent = text;
		};
		view.showResult();

		return view;
	}

	function messageView( role, text ) {
		const view = el( 'div', 'hc-msg hc-msg--' + role );
		const body = el( 'div', 'hc-msg__body' );

		body.innerHTML = markdownHtml( text );
		view.append( el( 'p', 'hc-msg__who', 'user' === role ? 'Support agent' : 'Claude' ), body );

		return view;
	}

	function stepView( step ) {
		return 'call' === step.type ? callView( step.exchange ) : messageView( step.type, step.text );
	}

	/** The recorded session: one tab per scenario, shown in full, with a Play button to watch it unfold. */
	function initReplay( root ) {
		const session = data.session;

		if ( ! root || ! session ) {
			return;
		}

		const tabs = el( 'div', 'hc-tabs' );
		const controls = el( 'div', 'hc-controls' );
		const play = el( 'button', 'hc-button', '▶ Play this session' );
		const transcript = el( 'div', 'hc-transcript' );
		let current = 0;
		let run = 0;

		tabs.setAttribute( 'role', 'tablist' );
		transcript.setAttribute( 'aria-live', 'polite' );
		play.type = 'button';

		function showAll() {
			run++;
			transcript.replaceChildren( ...session.scenarios[ current ].steps.map( stepView ) );
		}

		async function playSession() {
			const id = ++run;
			transcript.replaceChildren();

			for ( const step of session.scenarios[ current ].steps ) {
				if ( id !== run ) {
					return;
				}

				const view = stepView( step );
				transcript.append( view );

				if ( 'call' === step.type ) {
					view.classList.add( 'is-running' );
					view.querySelector( '.hc-call__result' ).textContent = 'calling';
					await wait( 700 );
					view.classList.remove( 'is-running' );
					view.showResult();
				}

				await wait( 'call' === step.type ? 300 : 1200 );
			}
		}

		session.scenarios.forEach( ( scenario, index ) => {
			const tab = el( 'button', 'hc-tab', scenario.title );
			tab.type = 'button';
			tab.setAttribute( 'role', 'tab' );
			tab.addEventListener( 'click', () => {
				current = index;
				[ ...tabs.children ].forEach( ( other, i ) => other.setAttribute( 'aria-selected', String( i === index ) ) );
				showAll();
			} );
			tabs.append( tab );
		} );

		play.addEventListener( 'click', playSession );
		controls.append( play );

		if ( data.assistantUrl ) {
			root.append( el( 'h3', 'hc-replay-title', 'Or replay a recorded session' ) );
		}

		root.append(
			tabs,
			controls,
			transcript,
			el( 'p', 'hc-muted hc-small', 'Recorded on ' + session.recorded + ' in ' + session.client + ', using the session-based MCP revision ' + session.protocol + '. The password is masked.' )
		);
		tabs.firstChild.click();
	}

	/** Sends a JSON POST request to one of the page's endpoints. */
	async function post( url, body ) {
		const response = await fetch( url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( body ),
		} );
		const json = await response.json();

		if ( ! response.ok ) {
			throw new Error( json.message || 'HTTP ' + response.status );
		}

		return json;
	}

	/** The live console: pick an example or write your own request, send it, see both sides. */
	function initConsole( root ) {
		if ( ! root ) {
			return;
		}

		const methods = [ 'tools/list', 'tools/call', 'resources/list', 'resources/read', 'prompts/list', 'prompts/get' ];
		const form = el( 'form', 'hc-console__form' );
		const output = el( 'div', 'hc-console__output' );

		form.innerHTML =
			'<label class="hc-field"><span>Example</span><select name="preset"></select></label>' +
			'<label class="hc-field"><span>Method</span><select name="method">' +
			methods.map( ( method ) => '<option>' + method + '</option>' ).join( '' ) +
			'</select></label>' +
			'<label class="hc-field hc-field--wide"><span>Params (JSON)</span><textarea name="params" rows="7" spellcheck="false"></textarea></label>' +
			'<div class="hc-console__actions"><button class="hc-button" type="submit">Send request</button><p class="hc-console__note"></p></div>';

		const { preset, method, params } = form.elements;
		const button = form.querySelector( 'button' );
		const note = form.querySelector( '.hc-console__note' );

		data.presets.forEach( ( item, index ) => preset.append( new Option( item.label, index ) ) );

		preset.addEventListener( 'change', () => {
			const item = data.presets[ preset.value ];
			method.value = item.method;
			params.value = JSON.stringify( item.params, null, 2 );
			note.textContent = item.note;
		} );
		preset.dispatchEvent( new Event( 'change' ) );

		form.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();

			let parsed;
			try {
				parsed = JSON.parse( params.value.trim() || '{}' );
			} catch ( error ) {
				output.replaceChildren( el( 'p', 'hc-error', 'Params must be valid JSON: ' + error.message ) );
				return;
			}

			button.disabled = true;
			button.textContent = 'Sending…';

			try {
				output.replaceChildren( exchangeView( await post( data.consoleUrl, { method: method.value, params: parsed } ) ) );
			} catch ( error ) {
				output.replaceChildren( el( 'p', 'hc-error', error.message ) );
			} finally {
				button.disabled = false;
				button.textContent = 'Send request';
			}
		} );

		root.append( form, output );

		// Send the first example once the console scrolls into view, so it shows a live response straight away.
		const observer = new IntersectionObserver( ( entries ) => {
			if ( entries.some( ( entry ) => entry.isIntersecting ) ) {
				observer.disconnect();
				form.requestSubmit();
			}
		} );
		observer.observe( root );
	}

	/** The live assistant: Claude answers through this site's MCP server, and every call is shown. */
	function initAssistant( root ) {
		if ( ! root || ! data.assistantUrl ) {
			return;
		}

		const form = el( 'form', 'hc-ask' );
		const examples = el( 'div', 'hc-examples' );
		const transcript = el( 'div', 'hc-transcript' );

		form.innerHTML =
			'<input name="question" type="text" minlength="3" maxlength="300" required aria-label="Customer question" placeholder="Ask a customer question, for example: How do I return an item?">' +
			'<button class="hc-button" type="submit">Ask Claude</button>';

		const input = form.elements.question;
		const button = form.querySelector( 'button' );

		[ 'I was charged twice for one order', 'How long does shipping take?', 'Can I cancel my order?' ].forEach( ( question ) => {
			const chip = el( 'button', 'hc-chip', question );
			chip.type = 'button';
			chip.addEventListener( 'click', () => {
				input.value = question;
				form.requestSubmit();
			} );
			examples.append( chip );
		} );

		form.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();

			const pending = el( 'p', 'hc-pending', 'Claude is working…' );
			transcript.replaceChildren( messageView( 'user', input.value ), pending );
			button.disabled = true;

			try {
				const answer = await post( data.assistantUrl, { question: input.value } );
				pending.remove();

				for ( const exchange of answer.steps ) {
					transcript.append( callView( exchange ) );
					await wait( 250 );
				}

				transcript.append( messageView( 'assistant', answer.answer ), el( 'p', 'hc-muted hc-small', 'Answered live by ' + answer.model + '.' ) );
			} catch ( error ) {
				pending.replaceWith( el( 'p', 'hc-error', error.message ) );
			} finally {
				button.disabled = false;
			}
		} );

		root.append( form, examples, transcript );
	}

	initAssistant( document.querySelector( '[data-hc-assistant]' ) );
	initReplay( document.querySelector( '[data-hc-replay]' ) );
	initConsole( document.querySelector( '[data-hc-console]' ) );
} )();
