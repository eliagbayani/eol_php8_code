

def greet(msg, msg2):  # parameter is the input you define with your function e.g. msg
    print(f"My input: {msg} {msg2}")


# argument is the actual value of a given parameter
greet("msg ko ito", "another 1")
greet("msg ko ito2", "another 2")
print("-=" * 30)

# returns a None. A None is an object that represents the absence of a value.
print(greet("msg ko ito", "another 1"))

print("-=" * 30)


def get_greeting(name):
    return f"name is: {name}"


msg = get_greeting("Eli")
print(msg)
file = open("content.txt", "w")
file.write(msg)
print("-=" * 30)


def increment(num, by):
    return num + by


# Using Keyword arguments
x = increment(4, 10)
print(x)
x = increment(num=4, by=20)
print(x)
print("-=" * 30)


# Default arguments: just be sure the optional parameter comes after the required parameter
def increment2(num, by=1):
    return num + by


print(increment2(10))  # missing 2nd param
print(increment2(10, 10))
# --------------------------------------------------
print("-=" * 30)


# xargs - function with multiple arguments
# passing a variable number of arguments
def get_sum(*mga_numero):
    total = 0
    for number in mga_numero:
        print(number)
        total += number
    return total


x = get_sum(1, 2, 3, 4, 10, 20, 1000)
print(f"sum is: {x}")
# --------------------------------------------------
print("-=" * 30)


# xxargs = passing a dictionary as arguments
# using the double ** you can pass key-value pairs
def save_user(**user):
    print(user["name"])
    print(user["id"])

    # another complex data type called a dictionary e.g. {'id': 173, 'name': 'Eli', 'age': 52}
    print(user)


save_user(id=173, name="Eli", age=52)  # passing key-value pairs

# --------------------------------------------------
print("-=" * 30)

"""
Actually if u can prevent using global variables then don't use global variables
"""
message = "a"  # sample of a global variable. Declared outside any function


def greet(name):
    # this is bad practice: you don't change the value of a global var inside functions.
    """
    global message
    message = "b"
    """


greet("Eli")
print(message)

# --------------------------------------------------
print("-=" * 30)
"""
vscode tricks
- select the row, then hold the opt/start key then press arrow-keys to move selected text
- to move cursor from left to right: hold down the alt key and use arrow-keys
"""
# --------------------------------------------------
print("-=" * 30)


def fizz_buzz(input):
    if (input % 3 == 0) and (input % 5 == 0):
        return "fizz_buzz"
    elif input % 3 == 0:
        return "fizz"
    elif input % 5 == 0:
        return "buzz"
    else:
        return input


print(fizz_buzz(15))
# -------------------------------------------------- Data Structures
print("-=" * 30)
# Lists
letters = ["a", "b", "c"]
matrix = [[1, 2], [2, 3]]
zeros = [0] * 5
print(zeros)
combined = zeros+letters
print(combined)

numbers = list(range(20))
print(numbers)

chars = list("Hello World")
print(chars)
print(len(chars))

# Accessing items
letters = list("abcd")
print(letters)
letters[0] = "A"
print(letters)
print(letters[0:3])  # start from 1st index and count 3 items
print(letters[::2])  # get every 2nd item

numbers = list(range(20))
print(numbers)
print(numbers[::-1])  # list in reverse order

# -------------------------------------------------- Data Structures
print("-=" * 30)

# Unpacking list
numbers = [1, 2, 3, 4, 5, 6, 7, 8, 9]
first, *other, last = numbers
print(first, last)
print(other)
# -------------------------------------------------- Data Structures
print("-=" * 30)

# Looping over Lists
letters = ["a", "b", "c"]
for letter in letters:
    print(letter)

for index, letter in enumerate(letters):
    print(index, letter)

# add, remove item from list
letters = ["a", "b", "c", "d"]
# Add, 2 ways
letters.append("e")  # will add after the last item
print(letters)
letters.insert(0, "1")  # will add in the beginning of the items
print(letters)
# Remove
letters.pop()  # remove last item
print(letters)
letters.pop(1)  # remove char on the given index no.
print(letters)
# remove the 1st occurrence of the specified item from the list
letters.remove("c")
print(letters)

del letters[0]
print(letters)

letters = ["a", "b", "c", "d"]
del letters[0:2]  # delete from 1st item and count 2
print(letters)

letters.clear()  # truncates the list
print(letters)

# -------------------------------------------------- Finding items
print("-=" * 30)
letters = ["a", "b", "c", "d"]
print(letters.count("f"))  # will give zero 0
if "d" in letters:
    print(letters.index("d"))
# -------------------------------------------------- Sorting lists
print("-=" * 30)
numbers = [3, 51, 2, 8, 6]
numbers.sort()  # sort ascending
print(numbers)

numbers.sort(reverse=True)  # sort descending
print(numbers)

print("-=" * 30)
# sorted() function
numbers = [3, 51, 2, 8, 6]
print(sorted(numbers))  # sorted ascending
print(sorted(numbers, reverse=True))  # sorted descending
print(numbers)  # original list

# --------------------------------------------------
print("-=" * 30)
# sorting of complex types; like tuples
items = [
    ("Product 1", 10),
    ("Product 2", 9),
    ("Product 3", 12)
]
"""
items.sort() #Python won't sort this and will just return back the given list
print(items)
"""


def sort_item(item):
    return item[1]  # return the price value


items.sort(key=sort_item)
print(items)
# -------------------------------------------------- using lambda function or lambda expression to sort
# lambda is a one line anonymous function that can be passed to another function
# lambda parameters:expression
print("-=" * 30)
items = [
    ("Product 1", 10),
    ("Product 2", 9),
    ("Product 3", 12)
]
items.sort(key=lambda aytem: aytem[1])
print(items)
# -------------------------------------------------- another lambda example
print("-=" * 30)
items = [
    ("Product 1", 10),
    ("Product 2", 9),
    ("Product 3", 12)
]
# now let us get the list of prices from the original items list
prices = []  # initialize an empty list
for item in items:
    prices.append(item[1])
print(prices)
# -------------------------------------------------- same task now using the map function
prices = []
# generates a map object, which is another iterable
map_object = map(lambda item: item[1], items)
prices = list(map_object)
print(prices)
# -------------------------------------------------- Filter Function: use lambda to filter a list
# task: get only prices >= 10. Use the filter() function
filtered = []
map_object = filter(lambda item: item[1] >= 10, items)
filtered = list(map_object)
print(filtered)
# -------------------------------------------------- List Comprehensions VS using map() and filter() functions
print("-=" * 30)
items = [
    ("Product 1", 10),
    ("Product 2", 9),
    ("Product 3", 12)
]
# previously:
prices = list(map(lambda item: item[1], items))
filtered = list(filter(lambda item: item[1] >= 10, items))
print(prices)
print(filtered)
# now using list comprehensions: we will get the same results with cleaner code
prices2 = [item[1] for item in items]
print(prices2)
filtered2 = [item for item in items if item[1] >= 10]
print(filtered2)
# -------------------------------------------------- Zip Function
print("-=" * 30)
list1 = [1, 2, 3]
list2 = [10, 20, 30]
print(list(zip("abc", list1, list2)))
# -------------------------------------------------- Stacks
print("-=" * 30)
"""
Stack is LIFO; last in first out
Just a review of what are the falsy values:
- 0   zero
- ""  empty string
- []  empty list
"""

browsing_session = []
browsing_session.append(1)
browsing_session.append(2)
browsing_session.append(3)
print(browsing_session)
last = browsing_session.pop()  # simulate a back button
print(f"Removed from the stack after a Back button: {last}")
print(browsing_session)
print("Redirect to: ", browsing_session[-1])
if not browsing_session:
    print("Disable the Back button")

# Recap: these are the operations you can perform on a stack
browsing_session = []
browsing_session.append(1)      # add item from the end of the stack
browsing_session.pop()          # delete the last item of the stack
if not browsing_session:
    browsing_session[-1]        # get the last item of the stack
# -------------------------------------------------- Queues - continued on app2.py
