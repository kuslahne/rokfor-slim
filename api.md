---
layout: backend
title: Api
permalink: /api/
---

<h2>
<a id="get-some-data-read-only-api" class="anchor" href="#get-some-data-read-only-api" aria-hidden="true"><span aria-hidden="true" class="octicon octicon-link"></span></a>Get some Data: Read Only API</h2>

<h3>
<a id="access-key" class="anchor" href="#access-key" aria-hidden="true"><span aria-hidden="true" class="octicon octicon-link"></span></a>Access Key</h3>

<p>In order to access data, you need to set a user and define a read only api key
in the user profile. Adding a user is only possible if you are signed in as
root. There are two reasons why we use api keys for read only access. First, you
can define which data is published, second, a key is not a password. Even by
publishing a key, there's no way to log into the system and edit content.</p>

<p>Sending the key is done via a bearer authentification header or a access_token
query string. Sending a header is probably a better solution since the query
string won't be too cluttered and the api key probably does not show up in the
server log. The GET-call is probably a little bit more difficult to generate
though.</p>

<pre><code>GET /api/contributions/1/1?access_token=[key]

$ curl -H "Authorization: Bearer [key]" http://localhost:8080/api/contributions/1/1
</code></pre>

<h3>
<a id="current-api-routes" class="anchor" href="#current-api-routes" aria-hidden="true"><span aria-hidden="true" class="octicon octicon-link"></span></a>Current API Routes</h3>

<p><strong>Loading a collection of contributions with options:</strong></p>

<pre><code>GET /api/contributions/:issueid|:issueid-:issueid.../:chapterid|:chapterid-:chapterid...?[options]

Options:

- query=string                                                (default: empty)
- filter=[id|date|sort|templateid[s]]:[lt[e]|gt[e]|eq|like]   (default: [omitted]:like)
- sort=[[id|date|name|sort]|chapter|issue|templateid[s]]:[asc|desc]           (default: sort:asc)
- limit=int                                                   (default: empty)
- offset=int                                                  (default: empty)
- data=[Fieldname|Fieldname|XX]                               (default: empty)
- populate=true|false                                         (default: false)
- verbose=true|false                                          (default: false)
- template=id                                                 (default: empty)
- status=draft|published|both                                 (default: published)

</code></pre>

<ul>
<li>  Query: search for a string within the contribution name or the text fields
Special queries: date:now is transformed into the current time stamp</li>
<li>  Filter: Applies the search string passed in query to certain fields, to the creation
date, the contribution id or sort number.
By default (if fields are omitted) the search query is applied to the name of the 
contribution and its content fields (full text search).
Furthermore, the comparison can be defined with equal, less than, greater than
or like (eq,lt,lte,gt,gte,like). Less and greater than does automatically cast
a string to a number.</li>
<li>  Sort: Sort the results by id, date, name or manual sort number (sort) either 
ascending or descending. It is also possible to sort by a custom id of a template field.
Contributions can also be sorted by chapter or issue.
Please note: You need to choose between id, date, name and sort. You can add one
custom sort field and the chapter and issue flag. i.E:
sort=date|chapter|issue|23 would sort by date, chapter, issue and the custom field 23.</li>
<li>  Limit and Offset: Create pages with a length of [limit] elements starting at
[offset].</li>
<li>  Data: Add additional field infos to the result set of a contributions.
For example, you need the title field of a contribution already in the
result set to create a multilingual menu. Or you need all images for a
slideshow over multiple contributions.</li>
<li>  Populate: Sends all data (true). Equals data=All|Available|Fields</li>
<li>  Verbose: Send complete Information about a dataset. In most cases, this 
is too much and just slowing down the connection.</li>
<li>  Template: limit to a certain template id</li>
<li>  Status: Including draft contributions, published contributions or both. Open
Contributions are never shown.</li>
</ul>

<p>Examples:</p>

<p>GET /api/contributions/1/14-5?query=New+York</p>

<pre><code>Searches for all contributions within issue 1 and chapters 14 and 5 for the String "New York".
</code></pre>

<p>GET /api/contributions/1/14-5?query=New+York&amp;filter=1|6:eq</p>

<pre><code>Searches for all contributions within issue 1 and chapters 14 and 5 for the exact String "New York" within both fields with the template id 1 and 6.
</code></pre>

<p>GET /api/contributions/1/14-5?query=12&amp;filter=sort:gtlimit=1</p>

<pre><code>Searches for all contributions within issue 1 and chapters 14 and 5 with a sort value &gt; 12 and a limitation to 1 item. This represents the next contribution in a manually sorted list, since the list is has a default sort order by 'sort, asc'.
</code></pre>

<p>GET /api/contributions/1/14-5?query=12&amp;filter=sort:lt&amp;sort=sort:desc&amp;limit=1</p>

<pre><code>Searches for all contributions within issue 1 and chapters 14 and 5 with a sort value &lt; 12 and a limitation to 1 item, order descending. This represents the previous contribution in a manually sorted list.
</code></pre>

<p>GET /api/contributions/12/19?limit=10&amp;offset=20</p>

<pre><code>Returns 10 contributions of issue 12 and chapter 19 starting after contribution 20.
</code></pre>

<p>GET /api/contributions/5-6-7/1-2-3?sort=date:desc&amp;data=Title|Subtitle</p>

<pre><code>Returns all contributions of issue 5, 6 and 7 and chapter 1, 2 and 3 ordered by date, descending. Additionally, populates each contribution entry with the content of the fields Title and Subtitle.
</code></pre>

<p>GET /api/contributions/1/1?populate=true&amp;verbose=true</p>

<pre><code>Returns all contributions of chapter 1 and issue 1. Adds all fields to each contribution and additionally prints a lot of information to each field and contribution.
</code></pre>

<p>GET /api/contributions/1/1?template=12</p>

<pre><code>Returns all contributions of chapter 1 and issue 1 based on the template 12
</code></pre>

<p><strong>Loading a single contribution:</strong></p>

<pre><code>GET /api/contribution/:id?[options]

Options:

- verbose=true|false                   (default: false)

</code></pre>

<ul>
<li>  Verbose: Send complete Information about a dataset. In most cases, this 
is too much and just slowing down the connection.</li>
</ul>

<p>Examples:</p>

<p>GET /api/contributions/12?verbose=true</p>

<pre><code>Loads all available data from contribution with the id 12
</code></pre>

<p><strong>Structural Queries</strong></p>

<pre><code>GET /api/books|issues|chapters/[:id]?[options]

Options:

- data=[Fieldname|Fieldname|XX]        (default: empty)
- populate=true|false                  (default: false)
- verbose=true|false                   (default: false)

</code></pre>

<ul>
<li>  Data: Add additional field infos to the result set of a contributions.
For example, you need the title field of a contribution already in the
result set to create a multilingual menu. Or you need all images for a
slideshow over multiple contributions.</li>
<li>  Populate: Sends all data (true). Equals data=All|Available|Fields</li>
<li>  Verbose: Send complete Information about a dataset. In most cases, this 
is too much and just slowing down the connection.</li>
</ul>

<p>Examples:</p>

<p>GET /api/books</p>

<pre><code>Shows all books available for the current api key
</code></pre>

<p>GET /api/chapters/3</p>

<pre><code>Shows all information about chapter 3
</code></pre>

<p>GET /api/issue/2?verbose=true&amp;populate=true</p>

<pre><code>Shows all information about issue 2. Additionally, raises the verbosity level and populates all data fields if a issue has backreferences to contributions.
</code></pre>