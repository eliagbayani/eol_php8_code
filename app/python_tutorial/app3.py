# -------------------------------------------------- Arrays []
"""
Use lists, or tuples for smaller items. And use arrays for 10K or more items.
"""
from array import array
# from MODULE import CLASS
print("-=" * 30)
numbers = array("i", [1, 2, 3])
# search google: python 3 typecode - to get the other typecodes...
numbers.append(4)
print(numbers)
print(numbers[2])
print(len(numbers))

# -------------------------------------------------- Sets {}
"""
Use sets for unique list of items. This shines when using it using the set mathematical methods like Union, etc.
"""
print("-=" * 30)
numbers = [1, 1, 2, 3, 4]  # creates a list object
print(type(numbers))
first = set(numbers)  # creates a set object
second = {1, 5}  # creates a new set object
print(second)

union = first | second
print(union)
intersect = first & second
print(intersect)
difference = first - second  # get items in first that we don't have in our second set
print(difference)

# get items either in first or send but not both
semantic_difference = first ^ second
print(semantic_difference)

# locate
if 4 in first:
    print("Exists")

# *Sets cannot be accessed using an index. e.g. first[0], otherwise use lists.

# -------------------------------------------------- Dictionaries
"""
A collection of key-value pairs
"""
print("-=" * 30)
point = {}  # empty dictionary
point = {"x": 1, "y": 2}
print(type(point))
print(point)

""" ways to create diff objects in Python
list()              x = [] -> an empty list
tuple()             x = () -> an empty tuple
set()               x = set() -> an empty set
dict()              x = {} -> an empty dictionary
"""
point = dict(x=1, y=2)  # same declaration as above
print(point)
point["x"] = 10
point["z"] = 173
print(point)

# check if exists
if "a" in point:
    print(point["a"])

print(point.get("a"))  # return None if "a" doesn't exist
print(point.get("a", "assign any default value"))  # or set a default value
# to delete
del point["x"]
print(point)
# loop dictionary
for key in point:
    print(key, point[key])
# another way to loop
for x in point.items():
    print(x, type(x))  # x is a tuple

for key, value in point.items():
    print(key, value)


# -------------------------------------------------- Dictionary Comprehension
print("-=" * 30)
"""
we can do comprehensions with lists, sets and dictionaries
"""
# review what is a list comprehension:
# [expression for item in items]

values = []
for x in range(5):
    values.append(x*2)
print(values)
values = [x*2 for x in range(5)]
print(values)  # a list of even numbers
print(type(values))

# u can also do a set comprehension by replacing [] with {}
values = {x*2 for x in range(5)}
print(values)  # a set of even numbers
print(type(values))

# Now let us do a dictionary comprehension:
values = {}  # empty dictionary
for x in range(5):
    values[x] = x*2
print(values)
print(type(values))

# using a dictionary comprehension
values = {x: x*2 for x in range(5)}
print(values)
print(type(values))
