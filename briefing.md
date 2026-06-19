Ik wil een task manager bouwen die lijkt op Linear.

Aanvankelijk moet dit vrij eenvoudig zijn. Ik moet projecten kunnen aanmaken. Per project moet er een board zijn (zowel kanban als list view).

Moet met livewire, alpinejs, fluxui pro en tailwindcss gebouwd worden.

Moeten reacties geplaats kunnen worden op tasks.

Moeten subtasks kunnen worden aangemaakt.
Moeten labels kunnen worden toegevoegd aan tasks.
Moeten deadlines kunnen worden toegevoegd aan tasks.
Moeten prioriteiten kunnen worden toegevoegd aan tasks.
Moeten assignees kunnen worden toegevoegd aan tasks.

Vooralsnog gaan we geen functionaliteit bouwen waarbij afbeeldingen of videos kunnen worden toegevoegd aan tasks. Die uploaden we nu ergens anders en plaatsen de link gewoon in de task of comment.

Maak een mooie UX / UI die vooral simpel is. Tasks moeten ook draggable zijn om volgorde (prio en van kolom te verplaatsen etc).

Maak een demo view die voor nu niet achter een login zit. User 1 wordt gewoon ingelogd gelijk.

Maak een plan.


Het idee is om deze task manager intern te gaan gebruiken met mijn collega's. Ik wil tickets met alle context als AI prompt kunnen kopieeren zodat ik ze naar mijn IDE kan plakken (in Claude Code).

Verder wil ik dit later gaan uitbouwen tot iets waarbij we dit als centraal systeem gebruiken voor al onze projecten en om overzicht te bewaren.

Wat is er volgens jou nu nog meer nodig om dit te bereiken? Hoe maken we de UX en UI zo goed dat dit heel intuitief werkt?

Één van de knelpunten die we nu hebben is dat veel projecten door elkaar lopen en dat sommige tickets gewoon niet meer up-to-date zijn. Ik wil eigenlijk een goede view hebben waarbij ik kan filteren. De initiele view zou alle tickets moeten bevatten in row vorm. Hier moet makkelijk gesleept kunnen worden om prio te bepalen. Prio geldt namelijk niet alleen binnen een project, maar ook ten opzichte van alle andere tasks, want ik kan maar aan 1 task tegelijk werken, dus moet ook weten aan welk project ik moet werken op dit moment (welke dus de hoogste prio task heeft).


Maak de modal van het bekijken van een ticket beter. De activiteiten log moet niet boven de comments. Verder wil ik graag dat de beschrijving niet standaard in een textarea staat maar alleen als je er op klikt, en dan een flux:editor graag.
